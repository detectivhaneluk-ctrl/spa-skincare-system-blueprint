-- Appointments refinements: conflict-check indexes

-- Composite indexes for branch-scoped staff/room overlap queries
ALTER TABLE appointments ADD INDEX idx_appointments_branch_staff_range (branch_id, staff_id, start_at, end_at);
ALTER TABLE appointments ADD INDEX idx_appointments_branch_room_range (branch_id, room_id, start_at, end_at);
