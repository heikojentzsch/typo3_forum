CREATE TABLE tx_typo3forumdevbootstrap_owned (
    uid int unsigned NOT NULL auto_increment,
    logical_key varchar(190) NOT NULL default '',
    table_name varchar(190) NOT NULL default '',
    record_uid int unsigned NOT NULL default 0,
    fixture_version int unsigned NOT NULL default 1,
    created_at int unsigned NOT NULL default 0,
    PRIMARY KEY (uid),
    UNIQUE KEY logical_key (logical_key),
    KEY record (table_name, record_uid)
);

CREATE TABLE tx_typo3forumdevbootstrap_phase (
    uid int unsigned NOT NULL auto_increment,
    phase varchar(190) NOT NULL default '',
    fixture_version int unsigned NOT NULL default 1,
    completed_at int unsigned NOT NULL default 0,
    PRIMARY KEY (uid),
    UNIQUE KEY phase (phase)
);
