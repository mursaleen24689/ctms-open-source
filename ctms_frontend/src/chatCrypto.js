const encoder = new TextEncoder();
const decoder = new TextDecoder();
const localKeys = new Map();
const CHAT_AAD = encoder.encode('ctms-chat-global:v1');

function toBase64(bytes) {
  let binary = '';
  new Uint8Array(bytes).forEach(byte => { binary += String.fromCharCode(byte); });
  return btoa(binary);
}

function fromBase64(value) {
  const binary = atob(value);
  return Uint8Array.from(binary, char => char.charCodeAt(0));
}

function openKeyStore() {
  return new Promise((resolve, reject) => {
    if (!globalThis.indexedDB) return reject(new Error('This browser does not support secure local chat-key storage.'));
    const request = indexedDB.open('ctms-chat-keys-v1', 1);
    request.onupgradeneeded = () => request.result.createObjectStore('identities');
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error || new Error('Could not open secure key storage.'));
  });
}

async function persistPrivateKey(userId, key, keyId) {
  const record = { key_id: keyId, key };
  localKeys.set(String(userId), record);
  try {
    const db = await openKeyStore();
    await new Promise((resolve, reject) => {
      const tx = db.transaction('identities', 'readwrite');
      tx.objectStore('identities').put(record, String(userId));
      tx.oncomplete = resolve;
      tx.onerror = () => reject(tx.error);
    });
    db.close();
  } catch { /* session memory remains usable; recovery backup is still on the server */ }
  return key;
}

export async function loadLocalChatKey(userId, keyId) {
  if (localKeys.has(String(userId))) {
    const record = localKeys.get(String(userId));
    return record.key_id === keyId ? record.key : null;
  }
  try {
    const db = await openKeyStore();
    const key = await new Promise((resolve, reject) => {
      const request = db.transaction('identities', 'readonly').objectStore('identities').get(String(userId));
      request.onsuccess = () => resolve(request.result || null);
      request.onerror = () => reject(request.error);
    });
    db.close();
    if (key?.key_id === keyId) localKeys.set(String(userId), key);
    return key?.key_id === keyId ? key.key : null;
  } catch { return null; }
}

async function deriveBackupKey(passphrase, salt) {
  const material = await crypto.subtle.importKey('raw', encoder.encode(passphrase), 'PBKDF2', false, ['deriveKey']);
  return crypto.subtle.deriveKey(
    { name: 'PBKDF2', salt, iterations: 350000, hash: 'SHA-256' },
    material,
    { name: 'AES-GCM', length: 256 },
    false,
    ['encrypt', 'decrypt'],
  );
}

async function createPrivateBackup(userId, passphrase, privateBytes) {
  const salt = crypto.getRandomValues(new Uint8Array(16));
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const key = await deriveBackupKey(passphrase, salt);
  const additionalData = encoder.encode(`ctms-chat-private:v1:${userId}`);
  const ciphertext = await crypto.subtle.encrypt({ name: 'AES-GCM', iv, additionalData }, key, privateBytes);
  return JSON.stringify({ v: 1, salt: toBase64(salt), iv: toBase64(iv), ciphertext: toBase64(ciphertext) });
}

export async function createChatIdentity(userId, passphrase) {
  const pair = await crypto.subtle.generateKey(
    { name: 'RSA-OAEP', modulusLength: 3072, publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-256' },
    true,
    ['encrypt', 'decrypt'],
  );
  const [publicBytes, privateBytes] = await Promise.all([
    crypto.subtle.exportKey('spki', pair.publicKey),
    crypto.subtle.exportKey('pkcs8', pair.privateKey),
  ]);
  const privateKey = await crypto.subtle.importKey('pkcs8', privateBytes, { name: 'RSA-OAEP', hash: 'SHA-256' }, false, ['decrypt']);
  const digest = await crypto.subtle.digest('SHA-256', publicBytes);
  const identity = {
    key_id: Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join(''),
    public_key: toBase64(publicBytes),
    encrypted_private_backup: await createPrivateBackup(userId, passphrase, privateBytes),
  };
  await persistPrivateKey(userId, privateKey, identity.key_id);
  return { identity, privateKey };
}

export async function restoreChatIdentity(userId, keyId, passphrase, backup) {
  const record = JSON.parse(backup);
  if (record.v !== 1) throw new Error('This chat-key backup version is not supported.');
  const salt = fromBase64(record.salt);
  const iv = fromBase64(record.iv);
  const key = await deriveBackupKey(passphrase, salt);
  const additionalData = encoder.encode(`ctms-chat-private:v1:${userId}`);
  let privateBytes;
  try {
    privateBytes = await crypto.subtle.decrypt({ name: 'AES-GCM', iv, additionalData }, key, fromBase64(record.ciphertext));
  } catch { throw new Error('That recovery passphrase did not unlock this chat key.'); }
  const privateKey = await crypto.subtle.importKey('pkcs8', privateBytes, { name: 'RSA-OAEP', hash: 'SHA-256' }, false, ['decrypt']);
  await persistPrivateKey(userId, privateKey, keyId);
  return privateKey;
}

export async function encryptChatMessage(text, recipients) {
  if (!recipients.length) throw new Error('No users have registered a secure chat key yet.');
  const rawKey = crypto.getRandomValues(new Uint8Array(32));
  const contentKey = await crypto.subtle.importKey('raw', rawKey, 'AES-GCM', false, ['encrypt']);
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const ciphertext = await crypto.subtle.encrypt({ name: 'AES-GCM', iv, additionalData: CHAT_AAD }, contentKey, encoder.encode(text));
  const envelopes = {};
  await Promise.all(recipients.map(async recipient => {
    const publicKey = await crypto.subtle.importKey('spki', fromBase64(recipient.public_key), { name: 'RSA-OAEP', hash: 'SHA-256' }, false, ['encrypt']);
    const wrapped = await crypto.subtle.encrypt({ name: 'RSA-OAEP' }, publicKey, rawKey);
    envelopes[String(recipient.user_id)] = { key_id: recipient.key_id, wrapped_key: toBase64(wrapped) };
  }));
  return { ciphertext: toBase64(ciphertext), nonce: toBase64(iv), envelopes };
}

export async function decryptChatMessage(message, userId, privateKey) {
  const envelope = message.envelope;
  if (!envelope?.wrapped_key) return null;
  const rawKey = await crypto.subtle.decrypt({ name: 'RSA-OAEP' }, privateKey, fromBase64(envelope.wrapped_key));
  const contentKey = await crypto.subtle.importKey('raw', rawKey, 'AES-GCM', false, ['decrypt']);
  const plaintext = await crypto.subtle.decrypt(
    { name: 'AES-GCM', iv: fromBase64(message.nonce), additionalData: CHAT_AAD },
    contentKey,
    fromBase64(message.ciphertext),
  );
  return decoder.decode(plaintext);
}
