ALTER TABLE `lc_orders_comments`
ADD COLUMN `author_id` INT(11) NOT NULL DEFAULT '0' AFTER `order_id`;
-- --------------------------------------------------------
UPDATE `lc_pages`
SET dock = CAST(REGEXP_REPLACE(dock, ',.*$', '') AS CHAR);
-- --------------------------------------------------------
INSERT INTO `lc_settings`
(`setting_group_key`, `key`, `title`, `description`, `value`, `function`, `priority`, `date_updated`, `date_created`)
