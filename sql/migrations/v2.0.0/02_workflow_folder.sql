-- Migration V2.0.0 — 02 : folders + fk on workflow
CREATE TABLE IF NOT EXISTS llx_knot_workflow_folder (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(255) NOT NULL,
    color VARCHAR(16) NULL,
    parent_id INTEGER NULL,
    entity INTEGER NOT NULL DEFAULT 1,
    fk_user_creat INTEGER NULL,
    date_creation DATETIME NOT NULL,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_knot_folder_entity (entity),
    KEY idx_knot_folder_parent (parent_id)
) ENGINE=innodb;

ALTER TABLE llx_knot_workflow
    ADD COLUMN fk_folder INTEGER NULL AFTER status,
    ADD KEY idx_knot_workflow_folder (fk_folder);
