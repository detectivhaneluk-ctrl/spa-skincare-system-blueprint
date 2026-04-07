-- WAVE-CAL-UI-02: staff column ordering (per-user, per-branch) within Scheduled/Freelancers sections.

ALTER TABLE calendar_user_preferences
    ADD COLUMN staff_order_scheduled_ids JSON NULL AFTER hidden_staff_ids,
    ADD COLUMN staff_order_freelancer_ids JSON NULL AFTER staff_order_scheduled_ids;

