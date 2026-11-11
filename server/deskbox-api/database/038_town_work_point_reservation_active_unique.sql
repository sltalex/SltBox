-- Keep one active reservation per work point while allowing unlimited history.
-- Migration 032 used (point_id,status), which rejects the second expired row
-- for the same point and turns a normal retry into an HTTP 500.

-- The original unique index also happens to support the point_id foreign key.
-- Add a normal point_id index before dropping it so the foreign key remains valid.
SET @town_reservation_point_index_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'town_work_point_reservations'
      AND column_name = 'point_id'
      AND seq_in_index = 1
      AND index_name <> 'ux_town_work_point_reservation_status'
);
SET @town_reservation_add_point_index_sql := IF(
    @town_reservation_point_index_exists = 0,
    'ALTER TABLE town_work_point_reservations ADD INDEX ix_town_work_point_reservation_point (point_id)',
    'SELECT 1'
);
PREPARE town_reservation_add_point_index_stmt FROM @town_reservation_add_point_index_sql;
EXECUTE town_reservation_add_point_index_stmt;
DEALLOCATE PREPARE town_reservation_add_point_index_stmt;

SET @town_reservation_index_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'town_work_point_reservations'
      AND index_name = 'ux_town_work_point_reservation_status'
);
SET @town_reservation_drop_index_sql := IF(
    @town_reservation_index_exists > 0,
    'ALTER TABLE town_work_point_reservations DROP INDEX ux_town_work_point_reservation_status',
    'SELECT 1'
);
PREPARE town_reservation_drop_index_stmt FROM @town_reservation_drop_index_sql;
EXECUTE town_reservation_drop_index_stmt;
DEALLOCATE PREPARE town_reservation_drop_index_stmt;

-- MySQL 8 functional indexes use a NULL expression for every non-active row;
-- unique indexes allow any number of NULL values while still enforcing one
-- active row for each point.
SET @town_reservation_active_index_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'town_work_point_reservations'
      AND index_name = 'ux_town_work_point_reservation_active'
);
SET @town_reservation_add_index_sql := IF(
    @town_reservation_active_index_exists = 0,
    'ALTER TABLE town_work_point_reservations ADD UNIQUE KEY ux_town_work_point_reservation_active ((IF(status = ''active'', point_id, NULL)))',
    'SELECT 1'
);
PREPARE town_reservation_add_index_stmt FROM @town_reservation_add_index_sql;
EXECUTE town_reservation_add_index_stmt;
DEALLOCATE PREPARE town_reservation_add_index_stmt;
