CREATE TABLE tx_docx_editor_session (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT 0 NOT NULL,
    tstamp int(11) DEFAULT 0 NOT NULL,
    crdate int(11) DEFAULT 0 NOT NULL,
    deleted tinyint(4) DEFAULT 0 NOT NULL,

    file_hash varchar(64) DEFAULT '' NOT NULL,
    file_identifier varchar(512) DEFAULT '' NOT NULL,
    backend_user int(11) DEFAULT 0 NOT NULL,
    user_name varchar(255) DEFAULT '' NOT NULL,
    last_heartbeat int(11) DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    KEY file_hash (file_hash),
    KEY heartbeat (last_heartbeat)
);

CREATE TABLE tx_docx_editor_revision (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT 0 NOT NULL,
    tstamp int(11) DEFAULT 0 NOT NULL,
    crdate int(11) DEFAULT 0 NOT NULL,

    file_identifier varchar(512) DEFAULT '' NOT NULL,
    revision int(11) DEFAULT 0 NOT NULL,
    content_hash varchar(64) DEFAULT '' NOT NULL,
    saved_by int(11) DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    KEY file_identifier (file_identifier(191))
);
