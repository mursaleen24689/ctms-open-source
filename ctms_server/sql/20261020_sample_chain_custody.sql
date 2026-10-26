-- Append-only sample chain-of-custody events. Event times and actors are
-- assigned by the server; barcode_value is reserved for future scanner input.
CREATE TABLE IF NOT EXISTS sample_custody_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sample_id INT NOT NULL,
  actor_id INT NULL,
  event_type VARCHAR(40) NOT NULL,
  location VARCHAR(200) NULL,
  details TEXT NULL,
  barcode_value VARCHAR(128) NULL,
  event_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_custody_sample_time (sample_id, event_at, id),
  KEY idx_custody_barcode (barcode_value),
  CONSTRAINT fk_custody_sample FOREIGN KEY (sample_id) REFERENCES samples(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_custody_actor FOREIGN KEY (actor_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed a minimal historical trail from timestamps CTMS already records.
INSERT INTO sample_custody_events (sample_id, actor_id, event_type, location, details, event_at)
SELECT s.id, u.id, 'received', 'Sample intake', 'Initial custody record backfilled from sample registration.', s.created_at
FROM samples s LEFT JOIN users u ON u.id = s.received_by WHERE s.is_deleted = 0
  AND NOT EXISTS (SELECT 1 FROM sample_custody_events e WHERE e.sample_id = s.id AND e.event_type = 'received' AND e.location = 'Sample intake' AND e.details = 'Initial custody record backfilled from sample registration.');

INSERT INTO sample_custody_events (sample_id, actor_id, event_type, location, details, event_at)
SELECT a.sample_id, NULL, 'lab_assigned', 'Laboratory', JSON_OBJECT('lab_stream', a.lab_stream, 'status', a.status), COALESCE(a.assigned_at, NOW())
FROM sample_lab_assignments a JOIN samples s ON s.id = a.sample_id
WHERE NOT EXISTS (SELECT 1 FROM sample_custody_events e WHERE e.sample_id = a.sample_id AND e.event_type = 'lab_assigned' AND e.event_at = COALESCE(a.assigned_at, NOW()) AND JSON_UNQUOTE(JSON_EXTRACT(e.details, '$.lab_stream')) = a.lab_stream);

INSERT INTO sample_custody_events (sample_id, actor_id, event_type, location, details, event_at)
SELECT a.sample_id, u.id, 'testing_completed', a.lab_stream, 'Historical lab assignment marked complete.', a.completed_at
FROM sample_lab_assignments a JOIN samples s ON s.id = a.sample_id LEFT JOIN users u ON u.id = a.completed_by
WHERE a.status = 'complete' AND a.completed_at IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM sample_custody_events e WHERE e.sample_id = a.sample_id AND e.event_type = 'testing_completed' AND e.event_at = a.completed_at AND e.location = a.lab_stream);

INSERT INTO sample_custody_events (sample_id, actor_id, event_type, location, details, event_at)
SELECT r.sample_id, NULL, 'dispatched', 'Client dispatch', COALESCE(r.dispatch_notes, 'Historical report dispatch.'), r.dispatched_at
FROM reports r JOIN samples s ON s.id = r.sample_id WHERE r.is_deleted = 0 AND r.sample_id IS NOT NULL AND r.dispatched_at IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM sample_custody_events e WHERE e.sample_id = r.sample_id AND e.event_type = 'dispatched' AND e.event_at = r.dispatched_at AND e.location = 'Client dispatch');
