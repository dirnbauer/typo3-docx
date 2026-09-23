CREATE TABLE tt_content (
    pagesynctest_items int(11) unsigned DEFAULT '0' NOT NULL,
    pagesynctest_settings varchar(255) DEFAULT '' NOT NULL
);

CREATE TABLE tx_pagesynctest_item (
    foreign_table_parent_uid int(11) unsigned DEFAULT '0' NOT NULL,
    tablenames varchar(255) DEFAULT '' NOT NULL,
    fieldname varchar(255) DEFAULT '' NOT NULL
);
