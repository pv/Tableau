CREATE TABLE `responsibilities` (
  `id` int unsigned NOT NULL auto_increment,
  `name` text NOT NULL,
  `responsibility` text NOT NULL,
  `last_updated` text NOT NULL,
  PRIMARY KEY  (`id`)
);

CREATE TABLE `staff` (
  `id` int unsigned NOT NULL auto_increment,
  `name` text NOT NULL,
  `birthdate` text NOT NULL,
  `phone` text NOT NULL,
  `last_updated` text NOT NULL,
  PRIMARY KEY  (`id`)
);

