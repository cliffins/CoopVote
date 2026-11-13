-- Remove the retired announcement feature from the selected CoopVote database.
-- Deploy the matching application changes and back up announcements first.
-- This deletes stored announcements; it does not affect election notifications.
DROP TABLE IF EXISTS `announcements`;
