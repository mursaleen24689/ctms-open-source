import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { fetchTeamChat, hideTeamChatMessage, registerTeamChatIdentity, sendTeamChatMessage } from './api';
import { createChatIdentity, decryptChatMessage, encryptChatMessage, loadLocalChatKey, restoreChatIdentity } from './chatCrypto';

const panel = { background: '#fff', border: '1px solid var(--border)', borderRadius: 10, padding: 16 };
const field = { width: '100%', padding: '9px 11px', border: '1px solid var(--border)', borderRadius: 7, font: 'inherit' };

export default function SecureChat({ userId, userName, userRole = '' }) {
  const isAdmin = ['admin', 'superadmin'].includes(userRole);
  const [identity, setIdentity] = useState(null);
  const [users, setUsers] = useState([]);
  const [auditEvents, setAuditEvents] = useState([]);
  const [messages, setMessages] = useState([]);
  const [decrypted, setDecrypted] = useState({});
  const [privateKey, setPrivateKey] = useState(null);
  const [hasMore, setHasMore] = useState(false);
  const [passphrase, setPassphrase] = useState('');
  const [resetIdentity, setResetIdentity] = useState(false);
  const [text, setText] = useState('');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState('');
  const latestMessageId = useRef(0);

  const ingest = useCallback((data, { append = false } = {}) => {
    if (Object.hasOwn(data, 'identity')) setIdentity(data.identity || null);
    if (Object.hasOwn(data, 'users')) setUsers(data.users || []);
    if (Object.hasOwn(data, 'audit_events')) setAuditEvents(data.audit_events || []);
    if (Object.hasOwn(data, 'has_more')) setHasMore(Boolean(data.has_more));
    setMessages(previous => {
      const incoming = data.messages || [];
      if (incoming.length) latestMessageId.current = Math.max(latestMessageId.current, ...incoming.map(message => Number(message.id) || 0));
      if (!append) return incoming;
      const merged = new Map(previous.map(message => [String(message.id), message]));
      incoming.forEach(message => merged.set(String(message.id), message));
      return [...merged.values()].sort((a, b) => Number(a.id) - Number(b.id));
    });
  }, []);

  const refresh = useCallback(async ({ beforeId = 0, afterId = 0, append = false } = {}) => {
    const response = await fetchTeamChat(beforeId, afterId);
    ingest(response.data || {}, { append });
    return response.data || {};
  }, [ingest]);

  useEffect(() => {
    let active = true;
    const load = async () => {
      try {
        const response = await fetchTeamChat();
        if (!active) return;
        const data = response.data || {};
        ingest(data);
        if (data.identity?.key_valid) {
          const localKey = await loadLocalChatKey(userId, data.identity.key_id);
          if (active && localKey) setPrivateKey(localKey);
        } else if (data.identity) {
          setNotice('The stored chat public-key fingerprint does not match its key. Sending is disabled until the system administrator investigates.');
        }
      } catch (error) {
        if (active) setNotice(error.response?.data?.error || 'Could not load the secure team chat.');
      } finally {
        if (active) setLoading(false);
      }
    };
    load();
    const timer = isAdmin ? null : window.setInterval(() => {
      fetchTeamChat(0, latestMessageId.current).then(response => { if (active) ingest(response.data || {}, { append: true }); }).catch(() => {});
    }, 10000);
    return () => { active = false; if (timer) window.clearInterval(timer); };
  }, [ingest, isAdmin, userId]);

  useEffect(() => {
    let active = true;
    if (!privateKey) return undefined;
    (async () => {
      const values = {};
      for (const message of messages) {
        if (!message.envelope || Object.hasOwn(decrypted, message.id)) continue;
        try { values[message.id] = await decryptChatMessage(message, userId, privateKey); }
        catch { values[message.id] = null; }
      }
      if (active && Object.keys(values).length) setDecrypted(previous => ({ ...previous, ...values }));
    })();
    return () => { active = false; };
  }, [messages, privateKey, userId, decrypted]);

  const readyUsers = useMemo(() => users.filter(member => member.key_id && member.public_key && member.key_valid), [users]);
  const missingKeyCount = users.length - readyUsers.length;
  const activeAdmins = users.filter(member => ['admin', 'superadmin'].includes(member.role));
  const adminKeysReady = activeAdmins.length > 0 && activeAdmins.every(member => member.key_id && member.key_valid);

  const unlockOrCreate = async (event) => {
    event.preventDefault();
    if (!globalThis.isSecureContext || !globalThis.crypto?.subtle) {
      setNotice('Secure chat needs HTTPS, or localhost, and a browser with Web Crypto. Open CTMS using its trusted HTTPS address.');
      return;
    }
    if (identity && identity.key_valid === false && !resetIdentity) { setNotice('This chat key failed its server fingerprint check. Ask the system administrator to investigate, or reset it knowing that old messages cannot be recovered.'); return; }
    if (passphrase.length < 12) { setNotice('Use a chat recovery passphrase with at least 12 characters.'); return; }
    setBusy(true); setNotice('');
    try {
      if (identity && !resetIdentity) {
        const key = await restoreChatIdentity(userId, identity.key_id, passphrase, identity.encrypted_private_backup);
        setPrivateKey(key);
        setNotice('Secure chat unlocked. Your messages are decrypted only in this browser.');
      } else {
        const created = await createChatIdentity(userId, passphrase);
        await registerTeamChatIdentity(created.identity);
        setIdentity({ ...created.identity, key_valid: true });
        setPrivateKey(created.privateKey);
        setResetIdentity(false);
        await refresh();
        setNotice('Secure chat key created. Save the recovery passphrase somewhere private to restore chat on another PC.');
      }
      setPassphrase('');
    } catch (error) {
      setNotice(error.response?.data?.error || error.message || 'Could not unlock secure chat.');
    } finally { setBusy(false); }
  };

  const send = async (event) => {
    event.preventDefault();
    const messageText = text.trim();
    if (!messageText || !identity || !privateKey || busy) return;
    if (messageText.length > 2000) { setNotice('Messages are limited to 2,000 characters.'); return; }
    setBusy(true); setNotice('');
    try {
      const encrypted = await encryptChatMessage(messageText, readyUsers);
      await sendTeamChatMessage({ ...encrypted, key_id: identity.key_id });
      setText('');
      await refresh({ append: true });
    } catch (error) {
      setNotice(error.response?.data?.error || 'Could not send the encrypted message. Refresh and try again.');
    } finally { setBusy(false); }
  };

  const hideForMe = async (messageId) => {
    if (!window.confirm('Hide this message from your own chat view? The original encrypted record remains in the system log for everyone else.')) return;
    setBusy(true); setNotice('');
    try {
      await hideTeamChatMessage(messageId);
      await refresh();
      setDecrypted(previous => { const next = { ...previous }; delete next[messageId]; return next; });
    }
    catch (error) { setNotice(error.response?.data?.error || 'Could not hide this message.'); }
    finally { setBusy(false); }
  };

  const loadOlder = async () => {
    if (!hasMore || !messages.length) return;
    setBusy(true);
    try { await refresh({ beforeId: Number(messages[0].id), append: true }); }
    catch { setNotice('Could not load older messages.'); }
    finally { setBusy(false); }
  };

  const resetMyChatKey = () => {
    if (!window.confirm('Replace your lost chat key? This removes your local copy and creates a new one. Your old messages remain retained; administrators can review messages whose admin key envelope was saved when they were sent, but your new key cannot open your old copy.')) return;
    setPrivateKey(null);
    setResetIdentity(true);
    setDecrypted({});
    setNotice('Create a replacement key with a new recovery passphrase. This change will be recorded in the audit log.');
  };

  return <section style={{ ...panel, maxWidth: 1050, margin: '0 auto' }}>
    <header style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap', borderBottom: '1px solid var(--border)', paddingBottom: 12, marginBottom: 12 }}>
      <div><h2 style={{ margin: 0, color: 'var(--navy)' }}>Team chat · #general</h2><div style={{ fontSize: 12, color: 'var(--text-muted)' }}>Shared by active CTMS and Consultancy users</div></div>
      <button type="button" onClick={() => refresh().catch(() => setNotice('Could not refresh chat.'))} style={{ padding: '7px 10px' }}>Refresh</button>
    </header>
    <div role="note" style={{ background: '#eef6f4', border: '1px solid #b9d9d3', borderRadius: 7, padding: 10, marginBottom: 12, fontSize: 12, lineHeight: 1.5 }}>
      Messages are encrypted in the browser and retained as encrypted records. Every active system administrator must enroll a chat key before new messages can be sent; those admin keys are included in new messages so administrators can review the archive, including messages hidden by an individual user. Messages sent before an admin key was enrolled may not be administrator-readable. Administrator archive reads, messages, key resets, and hide actions are audit-logged. This is a managed team chat, not private from system administrators. “Hide for me” never deletes the retained record. Use your recovery passphrase to restore your own key on another PC. If you lose it, replace your key; an administrator cannot recreate your private key, but can read messages whose admin key envelope was saved at send time.
    </div>
    {notice && <div role="status" style={{ padding: 9, borderRadius: 6, marginBottom: 10, background: '#fbf1dd', color: '#71500f', fontSize: 12 }}>{notice}</div>}
    {loading ? <div style={{ padding: 24, textAlign: 'center' }}>Loading secure chat…</div> : !privateKey ? (
      <form onSubmit={unlockOrCreate} style={{ ...panel, background: '#fbfaf8', maxWidth: 620, margin: '18px auto' }}>
        <h3 style={{ margin: '0 0 7px', color: 'var(--navy)' }}>{identity && !resetIdentity ? 'Unlock your secure chat' : 'Create your secure chat key'}</h3>
        <p style={{ margin: '0 0 10px', fontSize: 12, color: 'var(--text-muted)' }}>{identity && !resetIdentity ? 'Enter the recovery passphrase you chose on your first device. It decrypts your private key backup locally.' : resetIdentity ? 'This replaces your lost key. Old records remain retained. Administrators can read messages only when their key envelope was included at send time; your new key cannot decrypt your old copy.' : 'Choose a unique recovery passphrase of at least 12 characters. It encrypts your private key backup so you can restore it on another Windows PC.'}</p>
        <label htmlFor="chat-recovery-passphrase" style={{ display: 'block', fontSize: 12, marginBottom: 5 }}>Chat recovery passphrase</label>
        <input id="chat-recovery-passphrase" type="password" autoComplete="new-password" value={passphrase} onChange={event => setPassphrase(event.target.value)} minLength={12} maxLength={200} required style={field} />
        <button disabled={busy || identity?.key_valid === false && !resetIdentity} style={{ marginTop: 10, padding: '8px 14px' }}>{busy ? 'Setting up…' : identity && !resetIdentity ? 'Unlock chat' : 'Create secure key'}</button>
        {identity && !resetIdentity && <button type="button" disabled={busy} onClick={resetMyChatKey} style={{ margin: '10px 0 0 8px', padding: '8px 12px' }}>Lost passphrase? Reset key</button>}
      </form>
    ) : <>
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap', fontSize: 11, color: 'var(--text-muted)', margin: '0 0 8px' }}>
        <span>Signed in as {userName}. {readyUsers.length} of {users.length} active accounts have a key{missingKeyCount ? `; ${missingKeyCount} cannot decrypt new messages yet.` : '.'}</span>
        <span>Browser encryption: AES‑256‑GCM and RSA‑OAEP.</span>
      </div>
      {isAdmin && <div role="note" style={{ background: '#fff5e6', border: '1px solid #e7c994', borderRadius: 7, padding: 9, marginBottom: 9, fontSize: 11, lineHeight: 1.45 }}>Administrator archive access is enabled. Opening and refreshing this archive is logged with your account. You can review messages hidden by users because hiding only changes that user’s view.</div>}
      {users.some(member => ['admin', 'superadmin'].includes(member.role) && (!member.key_id || !member.key_valid)) && <div role="alert" style={{ background: '#fae3df', border: '1px solid #e5aaa2', borderRadius: 7, padding: 9, marginBottom: 9, fontSize: 11 }}>New messages are paused until every active system administrator has created or restored a secure chat key.</div>}
      <details style={{ marginBottom: 9, fontSize: 11, color: 'var(--text-muted)' }}><summary style={{ cursor: 'pointer' }}>Secure-key fingerprints (compare privately for sensitive conversations)</summary><div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(220px,1fr))', gap: 6, marginTop: 7 }}>{readyUsers.map(member => <div key={member.user_id}><strong>{member.full_name}</strong><br /><code>{member.key_id.match(/.{1,4}/g)?.join(' ')}</code></div>)}</div></details>
      <div style={{ height: 'min(56vh, 560px)', minHeight: 240, overflowY: 'auto', border: '1px solid var(--border)', borderRadius: 8, padding: 10, background: '#fbfaf8' }}>
        {hasMore && <button type="button" onClick={loadOlder} disabled={busy} style={{ display: 'block', margin: '0 auto 10px', padding: '6px 10px' }}>Load older messages</button>}
        {messages.length === 0 && <div style={{ padding: 24, textAlign: 'center', color: 'var(--text-muted)' }}>No messages yet. Start the team conversation.</div>}
        {messages.map(message => {
          const own = Number(message.sender_id) === Number(userId);
          const body = decrypted[message.id];
          return <article key={message.id} style={{ margin: '8px 0', padding: '9px 10px', background: own ? '#eef6f4' : '#fff', border: '1px solid var(--border)', borderRadius: 7 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, alignItems: 'baseline' }}>
              <strong style={{ fontSize: 12 }}>{message.sender_name_snapshot}{own ? ' · you' : ''}</strong>
              <time style={{ fontSize: 10, color: 'var(--text-muted)' }}>{new Date(String(message.created_at).replace(' ', 'T') + (String(message.created_at).includes('Z') ? '' : '+05:00')).toLocaleString()}</time>
            </div>
            <div style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', marginTop: 5, fontSize: 13 }}>{typeof body === 'string' ? body : message.envelope ? 'Unable to decrypt this message with the current chat key.' : 'Encrypted message. Your key was not registered when it was sent.'}</div>
            <button type="button" disabled={busy} onClick={() => hideForMe(message.id)} style={{ marginTop: 5, border: 0, background: 'transparent', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 10 }}>Hide for me</button>
          </article>;
        })}
      </div>
      <form onSubmit={send} style={{ display: 'flex', gap: 8, marginTop: 10, alignItems: 'end' }}>
        <textarea value={text} onChange={event => setText(event.target.value)} maxLength={2000} rows={2} placeholder="Write to the team…" style={{ ...field, resize: 'vertical' }} onKeyDown={event => { if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); event.currentTarget.form?.requestSubmit(); } }} />
      <button disabled={busy || !text.trim() || readyUsers.length === 0 || !adminKeysReady} style={{ minWidth: 92, padding: '9px 14px' }}>{busy ? 'Working…' : 'Send'}</button>
      </form>
      <div style={{ fontSize: 10, textAlign: 'right', color: 'var(--text-muted)', marginTop: 4 }}>{text.length}/2000 · Enter to send · Shift+Enter for a new line</div>
      {isAdmin && <details style={{ marginTop: 12, borderTop: '1px solid var(--border)', paddingTop: 9 }}>
        <summary style={{ cursor: 'pointer', fontSize: 12, fontWeight: 700, color: 'var(--navy)' }}>Chat audit trail ({auditEvents.length} recent events)</summary>
        <div style={{ maxHeight: 260, overflow: 'auto', marginTop: 8 }}>
          {auditEvents.length === 0 ? <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>No logged chat events yet.</div> : auditEvents.map(event => <div key={event.id} style={{ borderBottom: '1px solid var(--border)', padding: '6px 2px', display: 'grid', gridTemplateColumns: 'minmax(125px, .8fr) minmax(135px, 1fr) minmax(180px, 1.4fr)', gap: 8, fontSize: 10 }}>
            <span>{new Date(String(event.created_at).replace(' ', 'T') + (String(event.created_at).includes('Z') ? '' : '+05:00')).toLocaleString()}</span>
            <strong>{event.full_name} · {event.action}</strong>
            <span>{event.message_id ? `Message #${event.message_id}` : 'Archive access'}{event.details_json ? ` · ${(() => { try { const details = JSON.parse(event.details_json); return details.reason || details.hash?.slice(0, 12) || details.scope || ''; } catch { return ''; } })()}` : ''}</span>
          </div>)}
        </div>
      </details>}
    </>}
  </section>;
}
