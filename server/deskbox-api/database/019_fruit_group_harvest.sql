-- Preserve existing units and pick operation identifiers; whole-type harvests can exceed INT.
ALTER TABLE fruit_pick_operations MODIFY COLUMN quantity BIGINT NOT NULL;
