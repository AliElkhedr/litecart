<?php

  if (isset($_POST['enable']) || isset($_POST['disable'])) {

    try {
      if (empty($_POST['vmods'])) throw new Exception(language::translate('error_must_select_vmods', 'You must select vMods'));

      foreach ($_POST['vmods'] as $vmod) {

        if (!empty($_POST['enable'])) {
          if (!is_file(FS_DIR_APP . 'vmods/' . pathinfo($vmod, PATHINFO_FILENAME) .'.disabled')) continue;
          rename(FS_DIR_APP . 'vmods/' . pathinfo($vmod, PATHINFO_FILENAME) .'.disabled', FS_DIR_APP . 'vmods/' . pathinfo($vmod, PATHINFO_FILENAME) .'.xml');
        } else {
          if (!is_file(FS_DIR_APP . 'vmods/' . pathinfo($vmod, PATHINFO_FILENAME) .'.xml')) continue;
          rename(FS_DIR_APP . 'vmods/' . pathinfo($vmod, PATHINFO_FILENAME) .'.xml', FS_DIR_APP . 'vmods/' . pathinfo($vmod, PATHINFO_FILENAME) .'.disabled');
        }
      }

      notices::add('success', language::translate('success_changes_saved', 'Changes saved'));
      header('Location: '. document::link());
      exit;

    } catch (Exception $e) {
      notices::add('errors', $e->getMessage());
    }
  }

  if (isset($_POST['delete'])) {

    try {
      if (empty($_POST['vmods'])) throw new Exception(language::translate('error_must_select_vmods', 'You must select vMods'));

      foreach ($_POST['vmods'] as $vmod) {
        unlink(FS_DIR_APP . 'vmods/' . pathinfo($vmod, PATHINFO_BASENAME));
      }

      notices::add('success', language::translate('success_changes_saved', 'Changes saved'));
      header('Location: '. document::link());
      exit;

    } catch (Exception $e) {
      notices::add('errors', $e->getMessage());
    }
  }

  if (isset($_POST['upload'])) {

    try {
      if (!isset($_FILES['vmod']['tmp_name']) || !is_uploaded_file($_FILES['vmod']['tmp_name'])) {
        throw new Exception(language::translate('error_must_select_file_to_upload', 'You must select a file to upload'));
      }

      $dom = new DOMDocument('1.0', 'UTF-8');

      $xml = file_get_contents($_FILES['vmod']['tmp_name']); // DOMDocument::load() does not support Windows paths so we use DOMDocument::loadXML()

      if (!@$dom->loadXML($xml)) {
        throw new Exception(language::translate('error_invalid_xml_file', 'Invalid XML file'));
      }

      if (!$dom->getElementsByTagName('modification')) {
        throw new Exception(language::translate('error_xml_file_is_not_valid_vmod', 'XML file is not a valid vMod file'));
      }

      $filename = FS_DIR_APP . 'vmods/' . pathinfo($_FILES['vmod']['name'], PATHINFO_FILENAME) .'.xml';

      if (is_file($filename)) {
        unlink($filename);
      }

      move_uploaded_file($_FILES['vmod']['tmp_name'], $filename);

      notices::add('success', language::translate('success_changes_saved', 'Changes saved'));
      header('Location: '. document::link());
      exit;

    } catch (Exception $e) {
      notices::add('errors', $e->getMessage());
    }
  }

// Table Rows
  $vmods = [];

  foreach (glob(FS_DIR_APP . 'vmods/*.{xml,disabled}', GLOB_BRACE) as $file) {
    $xml = simplexml_load_file($file);
    $vmods[] = [
      'filename' => pathinfo($file, PATHINFO_BASENAME),
      'type' => ($xml->getName() == 'vmod') ? 'vMod' : 'VQmod',
      'enabled' => preg_match('#\.xml$#', $file) ? true : false,
      'name' => isset($xml->name) ? $xml->name : $xml->title,
      'version' => $xml->version,
      'author' => $xml->author,
      'configurable' => !empty($xml->setting) ? true : false,
      'errors' => null,
    ];
  }

// Number of Rows
  $num_rows = count($vmods);

// Tests

  foreach ($vmods as $vmod_key => $vmod) {

    try {

      $vmod_file = FS_DIR_APP . 'vmods/' . $vmod['filename'];

      $dom = new \DOMDocument('1.0', 'UTF-8');
      $dom->preserveWhiteSpace = false;

      if (!$dom->loadXml(file_get_contents($vmod_file))) {
        throw new Exception(libxml_get_last_error());
      }

      switch ($dom->documentElement->tagName) {

        case 'vmod': // vMod
          $parsed_vmod = vmod::parse_vmod($dom, $vmod_file);
          break;

        case 'modification': // vQmod
          $parsed_vmod = vmod::parse_vqmod($dom);
          break;

        default:
          throw new Exception("File ($file) is not a valid vmod or vQmod");
      }

      foreach (array_keys($parsed_vmod['files']) as $key) {
        $patterns = explode(',', $parsed_vmod['files'][$key]['name']);

        foreach ($patterns as $pattern) {
          $path_and_file = $parsed_vmod['files'][$key]['path'].$pattern;

        // Apply path aliases
          if (!empty(vmod::$aliases)) {
            $path_and_file = preg_replace(array_keys(vmod::$aliases), array_values(vmod::$aliases), $path_and_file);
          }

          $files = glob(FS_DIR_APP . $path_and_file);

          if (empty($files)) {
            throw new Exception('No files matching pattern');
          }

          foreach ($files as $file) {

            if (!is_file($file)) throw new Exception('File does not exist');

            $buffer = file_get_contents($file);

            foreach ($parsed_vmod['files'][$key]['operations'] as $i => $operation) {

              if (!empty($operation['ignoreif']) && preg_match($operation['ignoreif'], $buffer)) {
                continue;
              }

              if (!preg_match_all($operation['find']['pattern'], $buffer, $matches, PREG_OFFSET_CAPTURE)) {
                switch ($operation['onerror']) {
                  case 'ignore':
                    continue 2;
                  case 'abort':
                  case 'warning':
                  default:
                    throw new Exception('Search not found', E_USER_WARNING);
                    continue 2;
                }
              }

              if (!empty($operation['find']['indexes'])) {
                rsort($operation['find']['indexes']);

                foreach ($operation['find']['indexes'] as $index) {
                  $index = $index - 1; // [0] is the 1st in computer language

                  if ($found > $index) {
                    $buffer = substr_replace($buffer, preg_replace($operation['find']['pattern'], $operation['insert'], $matches[0][$index][0]), $matches[0][$index][1], strlen($matches[0][$index][0]));
                  }
                }

              } else {
                $buffer = preg_replace($operation['find']['pattern'], $operation['insert'], $buffer, -1, $count);

                if (!$count && $operation['onerror'] != 'skip') {
                  throw new Exception("Failed to perform insert");
                  continue;
                }
              }
            }
          }
        }
      }

    } catch (Exception $e) {
      $vmods[$vmod_key]['errors'] = $e->getMessage();
    }
  }
?>

<div class="card card-app">
  <div class="card-header">
    <div class="card-title">
      <?php echo $app_icon; ?> <?php echo language::translate('title_vmods', 'vMods'); ?>™
    </div>
  </div>

  <div class="card-action">
    <?php echo functions::form_draw_link_button(document::link(WS_DIR_ADMIN, ['doc' => 'edit_vmod'], ['app']), language::translate('title_create_new_vmod', 'Create New vMod'), '', 'add'); ?>
  </div>

  <?php echo functions::form_draw_form_begin('vmod_form', 'post', '', true); ?>

    <table class="table table-striped table-hover data-table">
      <thead>
        <tr>
          <th><?php echo functions::draw_fonticon('fa-check-square-o fa-fw', 'data-toggle="checkbox-toggle"'); ?></th>
          <th></th>
          <th class="main"><?php echo language::translate('title_name', 'Name'); ?></th>
          <th><?php echo language::translate('title_version', 'Version'); ?></th>
          <th><?php echo language::translate('title_filename', 'Filename'); ?></th>
          <th><?php echo language::translate('title_author', 'Author'); ?></th>
          <th><?php echo language::translate('title_type', 'Type'); ?></th>
          <th></th>
          <th></th>
          <th></th>
          <th></th>
        </tr>
      </thead>

      <tbody>
        <?php foreach ($vmods as $vmod) { ?>
        <tr class="<?php echo $vmod['enabled'] ? null : 'semi-transparent'; ?>">
          <td><?php echo functions::form_draw_checkbox('vmods[]', $vmod['filename']); ?></td>
          <td><?php echo functions::draw_fonticon('fa-circle', 'style="color: '. (!empty($vmod['status']) ? '#88cc44' : '#ff6644') .';"'); ?></td>
          <td><a class="link" href="<?php echo document::href_link(WS_DIR_ADMIN, ['doc' => 'edit_vmod', 'vmod' => $vmod['filename']], ['app']); ?>"><?php echo $vmod['name']; ?></a></td>
          <td><?php echo $vmod['version']; ?></td>
          <td><?php echo $vmod['filename']; ?></td>
          <td><?php echo $vmod['author']; ?></td>
          <td class="text-center"><?php echo $vmod['type']; ?></td>
          <td class="text-center">
            <a href="<?php echo document::href_link(WS_DIR_ADMIN, ['doc' => 'test', 'vmod' => $vmod['filename']], ['app']); ?>">
              <?php if (empty($vmod['errors'])) { ?>
              <span style="color: #8c4"><?php echo functions::draw_fonticon('ok'); ?> <?php echo language::translate('title_ok', 'OK'); ?></span>
              <?php } else { ?>
              <span style="color: #c00"><?php echo functions::draw_fonticon('warning'); ?> <?php echo language::translate('title_fail', 'Fail'); ?></span>
              <?php } ?>
            </a>
          </td>
          <td><?php if ($vmod['configurable']) { ?><a class="btn btn-default btn-sm" href="<?php echo document::href_link(WS_DIR_ADMIN, ['doc' => 'configure', 'vmod' => $vmod['filename']], ['app']); ?>" title="<?php echo language::translate('title_configure', 'Configure'); ?>"><?php echo functions::draw_fonticon('fa-cog'); ?></a><?php } ?></td>
          <td><?php if ($vmod['type'] == 'vMod') { ?><a class="btn btn-default btn-sm" href="<?php echo document::href_link(WS_DIR_ADMIN, ['doc' => 'view', 'vmod' => $vmod['filename']], ['app']); ?>" title="<?php echo language::translate('title_view', 'View'); ?>"><?php echo functions::draw_fonticon('fa-search'); ?></a><?php } ?></td>
          <td>
            <a class="btn btn-default btn-sm" href="<?php echo document::href_link(WS_DIR_ADMIN, ['doc' => 'download', 'vmod' => $vmod['filename']], ['app']); ?>" title="<?php echo language::translate('title_download', 'Download'); ?>"><?php echo functions::draw_fonticon('fa-download'); ?></a>
            <a class="btn btn-default btn-sm" href="<?php echo document::href_link(WS_DIR_ADMIN, ['doc' => 'edit_vmod', 'vmod' => $vmod['filename']], ['app']); ?>" title="<?php echo language::translate('title_edit', 'Edit'); ?>"><?php echo functions::draw_fonticon('fa-pencil'); ?></a>
          </td>
        </tr>
        <?php } ?>
      </tbody>

      <tfoot>
        <tr>
          <td colspan="11"><?php echo language::translate('title_vmods', 'vMods'); ?>: <?php echo $num_rows; ?></td>
        </tr>
      </tfoot>
    </table>

    <div class="card-body">
      <div class="row">
        <div class="col-md-6">
          <fieldset id="actions" disabled>
            <legend><?php echo language::translate('text_with_selected', 'With selected'); ?>:</legend>

            <ul class="list-inline">
              <li>
                <div class="btn-group">
                  <?php echo functions::form_draw_button('enable', language::translate('title_enable', 'Enable'), 'submit', '', 'on'); ?>
                  <?php echo functions::form_draw_button('disable', language::translate('title_disable', 'Disable'), 'submit', '', 'off'); ?>
                </div>
              </li>
              <li>
                <?php echo functions::form_draw_button('delete', language::translate('title_delete', 'Delete'), 'submit', 'class="btn btn-danger" onclick="'. functions::escape_html('if(!confirm("'. language::translate('text_are_you_sure', 'Are you sure?') .'")) return false;') .'"', 'delete'); ?>
              </li>
            </ul>
          </fieldset>
      </div>

      <div class="col-md-6">
        <fieldset>
          <legend><?php echo language::translate('title_upload_new_vmod', 'Upload a New vMod'); ?>:</legend>

          <div class="input-group">
            <?php echo functions::form_draw_file_field('vmod', 'accept="application/xml"'); ?>
            <?php echo functions::form_draw_button('upload', language::translate('title_upload', 'Upload'), 'submit'); ?>
          </div>
        </fieldset>
      </div>
    </div>

  <?php echo functions::form_draw_form_end(); ?>
</div>

<script>
  $('.data-table :checkbox').change(function() {
    $('#actions').prop('disabled', !$('.data-table :checked').length);
  }).first().trigger('change');
</script>