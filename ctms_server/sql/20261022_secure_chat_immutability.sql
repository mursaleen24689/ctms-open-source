-- A user may hide a chat item for their own view, but message/audit records
-- cannot be edited or physically removed through SQL either.
DROP TRIGGER IF EXISTS ctms_chat_message_no_update;
CREATE TRIGGER ctms_chat_message_no_update BEFORE UPDATE ON ctms_chat_messages
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Encrypted chat records are immutable';

DROP TRIGGER IF EXISTS ctms_chat_message_no_delete;
CREATE TRIGGER ctms_chat_message_no_delete BEFORE DELETE ON ctms_chat_messages
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Encrypted chat records are retained';

DROP TRIGGER IF EXISTS ctms_chat_audit_no_update;
CREATE TRIGGER ctms_chat_audit_no_update BEFORE UPDATE ON ctms_chat_audit_events
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Chat audit records are immutable';

DROP TRIGGER IF EXISTS ctms_chat_audit_no_delete;
CREATE TRIGGER ctms_chat_audit_no_delete BEFORE DELETE ON ctms_chat_audit_events
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Chat audit records are retained';

DROP TRIGGER IF EXISTS ctms_chat_hidden_no_update;
CREATE TRIGGER ctms_chat_hidden_no_update BEFORE UPDATE ON ctms_chat_hidden_messages
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Chat hide events are immutable';

DROP TRIGGER IF EXISTS ctms_chat_hidden_no_delete;
CREATE TRIGGER ctms_chat_hidden_no_delete BEFORE DELETE ON ctms_chat_hidden_messages
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Chat hide events are retained';
