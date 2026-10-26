import { useState, useEffect, Component, lazy, Suspense } from 'react';
const Dashboard = lazy(() => import('./Dashboard'));
import { loginUser, getLoginCaptcha, beginMfaSetup, confirmMfaSetup, verifyMfaLogin, saveToken, getToken, clearToken, sendForgotPassword, changeOwnPassword } from './api';
import InstitutionFooter from './InstitutionFooter';

// ── Role → Dashboard mapping (unchanged from previous version) ──
const ROLE_MAP = {
  superadmin: (u) => ({ role:'admin',   stream: u.lab_stream||'', name: u.full_name, isSuperadmin: true }),
  hod:        (u) => ({ role:'hod',     stream: '',               name: u.full_name }),
  chairman:   (u) => ({ role:'hod',     stream: '',               name: u.full_name }),
  deo:        (u) => ({ role:'deo',     stream: '',               name: u.full_name }),
  mapping:    (u) => ({ role:'mapping',   stream: '',               name: u.full_name }),
  pa:         (u) => ({ role:'pa',        stream: '',               name: u.full_name }),
  reception:  (u) => ({ role:'reception', stream: '',               name: u.full_name }),
  tester:     (u) => ({ role:'tester',  stream: u.lab_stream||'', name: u.full_name }),
  technician: (u) => ({ role:'tester',  stream: u.lab_stream||'', name: u.full_name }),
};

// ── Forced credential rotation (A-4) — full-screen gate ─────────
function PasswordChangeGate({ name, onDone, onLogout }) {
  const [current, setCurrent] = useState('');
  const [next, setNext]       = useState('');
  const [confirm, setConfirm] = useState('');
  const [msg, setMsg]         = useState('');
  const [busy, setBusy]       = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    setMsg('');
    if (next.length < 8 || next.length > 72) { setMsg('⚠️ New password must be between 8 and 72 characters.'); return; }
    if (next !== confirm) { setMsg('⚠️ The two new passwords do not match.'); return; }
    if (next === current) { setMsg('⚠️ The new password must be different from the current one.'); return; }
    setBusy(true);
    try {
      const res = await changeOwnPassword(current, next);
      // The old session token was revoked by the rotation — switch to
      // the fresh one the server issued with this response.
      if (res.data?.token) saveToken(res.data.token);
      alert('✅ Password updated. Welcome to CTMS Core.');
      onDone();
    } catch (err) {
      setMsg('❌ ' + (err.response?.data?.error || 'Change failed. Try again.'));
    } finally { setBusy(false); }
  };

  return (
    <div style={L.page}>
      <div style={L.card} className="ctms-rise">
        <div style={L.logoRow}><HexMark size={40} />
          <div><div style={L.brandName}>CTMS Core</div><div style={L.brandSub}>Security update required</div></div>
        </div>
        <p style={{ fontSize: '13px', color: '#555', margin: '0 0 16px' }}>
          Hello <strong>{name}</strong> — as part of the system security update, you must set a new
          password before continuing. Choose something you don't use anywhere else.
        </p>
        {msg && <div style={L.errBox}>{msg}</div>}
        <form onSubmit={submit}>
          <div style={L.field}><label style={L.label}>Current password</label>
            <input type="password" required autoFocus value={current} onChange={e => setCurrent(e.target.value)} style={L.input} /></div>
          <div style={L.field}><label style={L.label}>New password (8–72 characters)</label>
            <input type="password" required minLength={8} maxLength={72} value={next} onChange={e => setNext(e.target.value)} style={L.input} /></div>
          <div style={L.field}><label style={L.label}>Repeat new password</label>
            <input type="password" required value={confirm} onChange={e => setConfirm(e.target.value)} style={L.input} /></div>
          <button type="submit" style={L.btn} disabled={busy}>{busy ? 'Saving…' : 'Set new password & continue'}</button>
        </form>
        <div style={L.footer}><span onClick={onLogout} style={L.forgotLink}>Sign out instead</span></div>
      </div>
      <InstitutionFooter style={L.loginFootnote} />
    </div>
  );
}

// ── Error boundary (audit A16): a render crash anywhere in the app
// must show a recoverable message, never a blank white page.
class ErrorBoundary extends Component {
  constructor(props) { super(props); this.state = { hasError: false, errMsg: '' }; }
  static getDerivedStateFromError(err) { return { hasError: true, errMsg: String(err?.message || err) }; }
  componentDidCatch(err) { console.error('CTMS render error:', err); }
  render() {
    if (this.state.hasError) {
      return (
        <div style={{ minHeight: '100vh', display: 'flex', flexDirection: 'column',
                      alignItems: 'center', justifyContent: 'center', fontFamily: 'sans-serif',
                      background: '#f0f2f5', padding: 24, textAlign: 'center' }}>
          <h2 style={{ color: '#8C2F24' }}>Something went wrong on this screen.</h2>
          <p style={{ color: '#555', maxWidth: 480 }}>
            The rest of the system is unaffected. Reload the page to continue —
            your data is safe in the database.
          </p>
          {/* diagnostic line — helps the developer pinpoint the exact screen */}
          <p style={{ color: '#a39a8d', fontSize: 11, fontFamily: 'monospace', maxWidth: 640,
                      overflowWrap: 'anywhere' }}>{this.state.errMsg}</p>
          <button onClick={() => window.location.reload()}
            style={{ padding: '10px 22px', background: '#1a5276', color: '#fff', border: 0,
                     borderRadius: 8, fontWeight: 700, cursor: 'pointer' }}>
            Reload CTMS
          </button>
          <InstitutionFooter style={L.loginFootnote} />
        </div>
      );
    }
    return this.props.children;
  }
}

// ── Hexagon mark — "sample cross-section" — the CTMS signature ──
function HexMark({ size = 56 }) {
  return (
    <svg width={size} height={size} viewBox="0 0 100 100" aria-hidden="true">
      <polygon points="50,4 93,27 93,73 50,96 7,73 7,27" fill="#1B1E24" />
      <polygon points="50,4 93,27 93,73 50,96 7,73 7,27" fill="none" stroke="#C2703D" strokeWidth="5" />
      <circle cx="50" cy="50" r="16" fill="#C2703D" />
    </svg>
  );
}

function App() {
  const [isLoggedIn,  setIsLoggedIn]  = useState(false);
  const [userSession, setUserSession] = useState(null);
  const [email,       setEmail]       = useState('');
  const [password,    setPassword]    = useState('');
  const [error,       setError]       = useState('');
  const [isLoading,   setIsLoading]   = useState(false);
  const [showPwd,     setShowPwd]     = useState(false);
  const [captchaId, setCaptchaId] = useState('');
  const [captchaImage, setCaptchaImage] = useState('');
  const [captchaAnswer, setCaptchaAnswer] = useState('');
  const [captchaBusy, setCaptchaBusy] = useState(false);
  const [loginStage, setLoginStage] = useState('password');
  const [mfaTicket, setMfaTicket] = useState('');
  const [mfaSecret, setMfaSecret] = useState('');
  const [mfaQr, setMfaQr] = useState('');
  const [mfaCode, setMfaCode] = useState('');
  const [mfaBusy, setMfaBusy] = useState(false);
  const [recoveryCodes, setRecoveryCodes] = useState([]);
  const [pendingLoginResponse, setPendingLoginResponse] = useState(null);
  const [workingMode, setWorkingMode] = useState(() =>
    localStorage.getItem('ctms_working_mode') === 'direct' ? 'direct' : 'guided'
  );

  const refreshLoginCaptcha = async () => {
    setCaptchaBusy(true);
    setCaptchaAnswer('');
    try {
      const res = await getLoginCaptcha();
      if (!res.data?.success || !res.data?.challenge_id || !res.data?.image) {
        throw new Error(res.data?.error || 'Could not load the login security check.');
      }
      setCaptchaId(res.data.challenge_id);
      setCaptchaImage(res.data.image);
    } catch (err) {
      setCaptchaId('');
      setCaptchaImage('');
      setError(err.response?.data?.error || err.message || 'Could not load the login security check.');
    } finally {
      setCaptchaBusy(false);
    }
  };

  useEffect(() => { refreshLoginCaptcha(); }, []);

  const changeWorkingMode = (mode) => {
    const next = mode === 'direct' ? 'direct' : 'guided';
    localStorage.setItem('ctms_working_mode', next);
    setWorkingMode(next);
  };

  // Rotating welcome line — one orchestrated entrance, not scattered effects
  const greetings = [
    'Welcome to CTMS Core',
    'Department of Materials Engineering',
    'Example Technical University',
  ];
  const [greetIdx, setGreetIdx] = useState(0);
  useEffect(() => {
    const iv = setInterval(() => setGreetIdx(i => (i + 1) % greetings.length), 3500);
    return () => clearInterval(iv);
  }, []);

  // Session-expired notice: api.js redirects here when a token dies
  // mid-session (8-hour expiry or credential rotation).
  useEffect(() => {
    if (window.location.hash === '#expired') {
      setError('Your session has ended. Please log in again.');
      history.replaceState(null, '', window.location.pathname);
    }
  }, []);

  // Consultancy runs on a different origin during local development, so its
  // sign-out cannot clear this app's localStorage directly. Its return URL
  // carries this one-time marker; clear both the saved and in-memory session.
  useEffect(() => {
    const finishConsultancySignOut = () => {
      if (window.location.hash !== '#consultancy-signed-out') return;
      clearToken();
      localStorage.removeItem('ctms_session');
      setIsLoggedIn(false);
      setUserSession(null);
      setEmail('');
      setPassword('');
      setError('You have signed out of Consultancy and CTMS.');
      history.replaceState(null, '', window.location.pathname + window.location.search);
    };
    finishConsultancySignOut();
    window.addEventListener('hashchange', finishConsultancySignOut);
    return () => window.removeEventListener('hashchange', finishConsultancySignOut);
  }, []);

  // Keep other CTMS tabs signed out when this tab ends the shared session.
  useEffect(() => {
    const syncSignOut = (event) => {
      if (event.key !== 'ctms_token' || event.newValue !== null) return;
      localStorage.removeItem('ctms_session');
      setIsLoggedIn(false);
      setUserSession(null);
      setEmail('');
      setPassword('');
      setError('Your CTMS session was signed out in another tab.');
    };
    window.addEventListener('storage', syncSignOut);
    return () => window.removeEventListener('storage', syncSignOut);
  }, []);

  // Restore session on refresh
  useEffect(() => {
    const token = getToken();
    const saved = localStorage.getItem('ctms_session');
    if (token && saved) {
      try { setUserSession(JSON.parse(saved)); setIsLoggedIn(true); } catch { clearToken(); }
    }
  }, []);

  const finishLogin = (d) => {
    saveToken(d.token);
    const mapFn = ROLE_MAP[d.role] || ROLE_MAP['deo'];
    const session = mapFn(d);
    session.userId = Number(d.user_id || d.id || 0);
    session.must_change_password = !!d.must_change_password;
    session.consultancy_access = !!d.consultancy_access;
    session.can_technical_review = !!d.can_technical_review;
    localStorage.setItem('ctms_session', JSON.stringify(session));
    setUserSession(session);
    setIsLoggedIn(true);
    setLoginStage('password');
  };

  const handleLogin = async (e) => {
    e.preventDefault();
    if (!captchaId || captchaAnswer.length !== 6) {
      setError('Complete the six-character security check before signing in.');
      return;
    }
    setError(''); setIsLoading(true);
    let authenticated = false;
    try {
      const res = await loginUser(email.trim(), password, captchaId, captchaAnswer);
      const d = res.data;
      if (d.mfa_setup_required || d.mfa_required) {
        if (!d.login_ticket) throw new Error('The secure sign-in step could not be created.');
        setMfaTicket(d.login_ticket);
        setMfaCode('');
        if (d.mfa_setup_required) {
          const setup = await beginMfaSetup(d.login_ticket);
          const qrLibrary = await import('qrcode');
          const qr = await qrLibrary.default.toDataURL(setup.data.otpauth_uri, { width: 210, margin: 1, errorCorrectionLevel: 'M' });
          setMfaSecret(setup.data.secret);
          setMfaQr(qr);
          setLoginStage('mfa_setup');
        } else {
          setLoginStage('mfa_verify');
        }
        setError('');
      } else if (d.success) {
        authenticated = true;
        finishLogin(d);
      } else {
        setError(d.error || 'Login failed.');
      }
    } catch (err) {
      if (err.response) setError(err.response.data?.error || 'Invalid credentials.');
      else setError('Cannot reach the CTMS server. Make sure XAMPP is running.');
    } finally {
      if (!authenticated) await refreshLoginCaptcha();
      setIsLoading(false);
    }
  };

  const handleMfaSubmit = async (e) => {
    e.preventDefault();
    setError(''); setMfaBusy(true);
    try {
      const res = loginStage === 'mfa_setup'
        ? await confirmMfaSetup(mfaTicket, mfaCode)
        : await verifyMfaLogin(mfaTicket, mfaCode);
      if (loginStage === 'mfa_setup') {
        setPendingLoginResponse(res.data);
        setRecoveryCodes(res.data?.recovery_codes || []);
        setLoginStage('mfa_recovery');
      } else if (res.data?.success) {
        finishLogin(res.data);
      } else {
        throw new Error(res.data?.error || 'The code could not be verified.');
      }
    } catch (err) {
      setError(err.response?.data?.error || err.message || 'The security code could not be verified.');
    } finally { setMfaBusy(false); }
  };

  const cancelMfaLogin = () => {
    setLoginStage('password'); setMfaTicket(''); setMfaSecret(''); setMfaQr(''); setMfaCode('');
    setRecoveryCodes([]); setPendingLoginResponse(null); setPassword(''); setError('');
    refreshLoginCaptcha();
  };

  const handleLogout = () => {
    clearToken();
    localStorage.removeItem('ctms_session');
    setIsLoggedIn(false); setUserSession(null); setEmail(''); setPassword('');
  };

  const handleForgotPassword = async () => {
    const emailInput = window.prompt('Enter your registered email address:');
    if (!emailInput?.trim()) return;
    try {
      await sendForgotPassword(emailInput.trim());
      alert('Request submitted. The System Administrator will reset your password shortly.');
    } catch {
      alert('Request noted. Please also contact the System Administrator directly.');
    }
  };

  if (isLoggedIn && userSession) {
    // A-4: users flagged for credential rotation must change their
    // password before they can use the system.
    if (userSession.must_change_password) {
      return (
        <PasswordChangeGate
          name={userSession.name}
          onDone={() => {
            const s = { ...userSession, must_change_password: false };
            localStorage.setItem('ctms_session', JSON.stringify(s));
            setUserSession(s);
          }}
          onLogout={handleLogout}
        />
      );
    }
    return (
      <ErrorBoundary>
        <Suspense fallback={<div style={{ ...L.page, display: 'grid', placeItems: 'center', color: '#786F63', fontWeight: 700 }}>Opening CTMS…<InstitutionFooter style={L.loginFootnote} /></div>}>
          <Dashboard
            user={userSession.name}
            userId={userSession.userId || 0}
            role={userSession.role}
            isSuperadmin={!!userSession.isSuperadmin}
            canTechnicalReview={!!userSession.can_technical_review}
            labStream={userSession.stream}
            consultancyAccess={userSession.consultancy_access !== false}
            workingMode={workingMode}
            onWorkingModeChange={changeWorkingMode}
            onLogout={handleLogout}
          />
        </Suspense>
      </ErrorBoundary>
    );
  }

  return (
    <div style={L.page}>
      <LabBackdrop />

      <div style={L.card} className="ctms-rise">
        <div style={L.logoRow}>
          <HexMark size={52} />
          <div>
            <div style={L.brandName}>CTMS Core</div>
            <div style={L.brandSub}>Materials Testing Management</div>
          </div>
        </div>

        <div style={L.greetWrap}>
          <p key={greetIdx} style={L.greetText} className="ctms-fade">{greetings[greetIdx]}</p>
        </div>

        {error && <div style={L.errBox}>{error}</div>}

        {loginStage === 'password' ? <form onSubmit={handleLogin}>
          <div style={L.field}>
            <label style={L.label}>Username or work email</label>
            <input
              type="text" required autoFocus autoComplete="username" autoCapitalize="none" spellCheck="false"
              placeholder="username or name@Example University.edu.pk"
              value={email} onChange={e => setEmail(e.target.value)}
              style={L.input} disabled={isLoading}
            />
          </div>

          <div style={L.field}>
            <label style={L.label}>Password</label>
            <div style={{ position: 'relative' }}>
              <input
                type={showPwd ? 'text' : 'password'} required
                placeholder="••••••••••"
                value={password} onChange={e => setPassword(e.target.value)}
                style={{ ...L.input, paddingRight: '46px' }} disabled={isLoading}
              />
              <span
                onClick={() => setShowPwd(!showPwd)}
                style={L.eyeIcon}
                role="button" aria-label="Toggle password visibility"
              >
                {showPwd ? '🙈' : '👁️'}
              </span>
            </div>
          </div>

          <div style={L.field}>
            <label htmlFor="ctms-login-captcha" style={L.label}>Security check</label>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8 }}>
              {captchaImage ? (
                <img src={captchaImage} alt="Six-character login security code" width="220" height="68"
                  draggable="false" style={{ display: 'block', width: 220, height: 68, border: '1px solid #E5E0D8', borderRadius: 6, background: '#F9F7F2' }} />
              ) : (
                <div role="status" style={{ width: 220, height: 68, display: 'grid', placeItems: 'center', border: '1px solid #E5E0D8', borderRadius: 6, background: '#F9F7F2', color: '#786F63', fontSize: 12 }}>
                  {captchaBusy ? 'Loading security check…' : 'Security check unavailable'}
                </div>
              )}
              <button type="button" onClick={refreshLoginCaptcha} disabled={captchaBusy || isLoading}
                aria-label="Get a new security code" title="Get a new security code"
                style={{ border: '1px solid #E5E0D8', borderRadius: 7, background: '#fff', color: '#1B1E24', padding: '9px 10px', fontWeight: 700, cursor: captchaBusy || isLoading ? 'wait' : 'pointer' }}>
                {captchaBusy ? '…' : '↻ New'}
              </button>
            </div>
            <input id="ctms-login-captcha" type="text" required minLength={6} maxLength={6}
              inputMode="text" autoComplete="off" spellCheck="false" disabled={!captchaId || isLoading}
              value={captchaAnswer}
              onChange={e => setCaptchaAnswer(e.target.value.toUpperCase().replace(/[^A-HJ-KM-NP-Z2-9]/g, '').slice(0, 6))}
              placeholder="Enter the six characters" style={L.input} aria-describedby="ctms-captcha-help" />
            <div id="ctms-captcha-help" style={{ marginTop: 5, fontSize: 11, color: '#786F63' }}>
              A new code is required for every sign-in attempt.
            </div>
          </div>

          <button type="submit" style={{ ...L.btn, opacity: isLoading || !captchaId ? 0.7 : 1 }} disabled={isLoading || captchaBusy || !captchaId}>
            {isLoading ? 'Authenticating…' : !captchaId ? 'Loading security check…' : 'Sign in'}
          </button>
        </form> : loginStage === 'mfa_setup' ? (
          <form onSubmit={handleMfaSubmit}>
            <div style={{ ...L.helpBox, marginBottom: 14 }}>
              This is a one-time setup. Use an authenticator app on your phone; code generation works offline after setup.
            </div>
            {mfaQr && <img src={mfaQr} alt="Authenticator setup QR code" width="210" height="210" style={{ display: 'block', margin: '0 auto 10px', imageRendering: 'pixelated' }} />}
            <div style={{ ...L.helpBox, textAlign: 'center', marginBottom: 14 }}>
              If scanning is unavailable, enter this setup key in the authenticator app:<br />
              <strong style={{ display: 'block', marginTop: 6, fontFamily: 'monospace', letterSpacing: 1.2, overflowWrap: 'anywhere' }}>{mfaSecret}</strong>
            </div>
            <div style={L.field}>
              <label htmlFor="ctms-mfa-code" style={L.label}>Enter the six-digit app code to confirm setup</label>
              <input id="ctms-mfa-code" required inputMode="numeric" autoComplete="one-time-code" maxLength={6}
                value={mfaCode} onChange={e => setMfaCode(e.target.value.replace(/\D/g, '').slice(0, 6))} style={L.input} />
            </div>
            <button type="submit" style={L.btn} disabled={mfaBusy || mfaCode.length !== 6}>{mfaBusy ? 'Checking…' : 'Confirm and continue'}</button>
          </form>
        ) : loginStage === 'mfa_recovery' ? (
          <div>
            <div style={{ ...L.helpBox, marginBottom: 12 }}>
              Save these one-time recovery codes somewhere private. Each code works once if your authenticator device is unavailable. This list will not be shown again.
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 7, padding: 12, background: '#F9F7F2', borderRadius: 8, fontFamily: 'monospace', fontSize: 14 }}>
              {recoveryCodes.map(code => <strong key={code}>{code}</strong>)}
            </div>
            <button type="button" style={{ ...L.btn, marginTop: 14 }} onClick={() => pendingLoginResponse && finishLogin(pendingLoginResponse)}>I saved the codes — continue</button>
          </div>
        ) : (
          <form onSubmit={handleMfaSubmit}>
            <div style={{ ...L.helpBox, marginBottom: 14 }}>
              Enter the current six-digit code from your authenticator app. The app generates codes offline. You may enter a recovery code instead.
            </div>
            <div style={L.field}>
              <label htmlFor="ctms-mfa-code" style={L.label}>Authenticator or recovery code</label>
              <input id="ctms-mfa-code" required autoFocus autoComplete="one-time-code" maxLength={11}
                value={mfaCode} onChange={e => setMfaCode(e.target.value.toUpperCase().replace(/[^A-F0-9-]/g, '').slice(0, 11))} style={L.input} />
            </div>
            <button type="submit" style={L.btn} disabled={mfaBusy || mfaCode.length < 6}>{mfaBusy ? 'Checking…' : 'Verify and sign in'}</button>
          </form>
        )}

        <div style={L.footer}>
          {loginStage === 'password'
            ? <span onClick={handleForgotPassword} style={L.forgotLink}>Forgot password?</span>
            : loginStage !== 'mfa_recovery' && <span onClick={cancelMfaLogin} style={L.forgotLink}>Cancel and return to sign in</span>}
        </div>

        <div style={L.helpBox}>
          First login? Use the temporary password provided by your CTMS administrator. You will be asked to change it after signing in.
        </div>
      </div>

      <InstitutionFooter style={L.loginFootnote} className="ctms-fade" />
    </div>
  );
}

// Lightweight, decorative lab scene: SVG/CSS only, no canvas or runtime assets.
function LabBackdrop() {
  return (
    <div className="ctms-lab-scene" aria-hidden="true">
      <div className="ctms-lab-glow" />
      <div className="ctms-lab-grid" />
      <div className="ctms-lab-orbit ctms-lab-orbit-one" />
      <div className="ctms-lab-orbit ctms-lab-orbit-two" />
      <svg className="ctms-lab-blueprint" viewBox="0 0 720 520" fill="none">
        <g stroke="currentColor" strokeWidth="1.2">
          <path d="M86 345h162V315h72v-82h38v82h72v30h142" />
          <path d="M126 345v74h390v-74M248 315v-40h72m38 0h72v40M305 233v-54h34v54" />
          <circle cx="339" cy="165" r="18" /><circle cx="339" cy="165" r="7" />
          <path d="M330 165h18M339 156v18M84 432h544M102 423v18m34-18v18m34-18v18m34-18v18m34-18v18m34-18v18m34-18v18m34-18v18m34-18v18m34-18v18m34-18v18m34-18v18m34-18v18m34-18v18m34-18v18" />
          <path d="M110 110h94m-94 10h58M524 120h76m-76 10h46M510 205h112M510 215h68" />
          <path d="M115 252v-25h24v25m-12-25v-18m-20 43h40M560 272l26-26 26 26v28h-52zM572 272h28m-14-14v28" />
          <path d="M176 345v-30h42v30m306 0v-30h42v30" />
          <path d="M80 193h60m-30-30v60m510 15h38m-19-19v38" strokeDasharray="3 6" />
        </g>
        <g fill="currentColor" fontFamily="monospace" fontSize="11">
          <text x="86" y="455">CALIPER / 0.01 mm</text>
          <text x="470" y="185">HARDNESS / HV</text>
          <text x="95" y="96">MATERIALS LAB · Example University</text>
        </g>
        <g stroke="currentColor" strokeWidth="1">
          <path d="M86 350v12m162-12v12m-162-6h162M320 275h38m-38-4v8m38-8v8" />
          <circle cx="110" cy="193" r="3" fill="currentColor" />
          <circle cx="608" cy="225" r="3" fill="currentColor" />
        </g>
      </svg>
      <div className="ctms-lab-readout ctms-readout-a"><span>TEST SYSTEM</span><strong>READY <i /></strong></div>
      <div className="ctms-lab-readout ctms-readout-b"><span>INSTRUMENT CALIBRATION</span><strong>TRACEABLE <b>ISO</b></strong></div>
      <div className="ctms-lab-scan" />
      <div style={L.hexField} />
    </div>
  );
}

// ── Styles — Graphite / Copper / Teal / Paper system ─────────────
const L = {
  page: {
    width: '100%', minHeight: '100vh', height: 'auto', boxSizing: 'border-box', padding: '24px 16px 86px',
    display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center',
    background: 'radial-gradient(circle at 30% 20%, #262B33 0%, #1B1E24 55%, #14171C 100%)',
    position: 'relative', overflowX: 'hidden', overflowY: 'auto', fontFamily: "'IBM Plex Sans', sans-serif",
  },
  // Subtle hexagon grid texture — repeating SVG background, very low opacity
  hexField: {
    position: 'absolute', inset: 0, opacity: 0.05, pointerEvents: 'none',
    backgroundImage: `url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='60' height='52' viewBox='0 0 60 52'%3E%3Cpolygon points='30,2 56,16 56,40 30,50 4,40 4,16' fill='none' stroke='%23C2703D' stroke-width='1'/%3E%3C/svg%3E")`,
    backgroundSize: '60px 52px',
  },
  card: {
    position: 'relative', zIndex: 1, width: '420px',
    background: '#FFFFFF', borderRadius: '16px', padding: '38px 40px',
    boxShadow: '0 30px 70px rgba(0,0,0,0.45)',
    border: '1px solid rgba(255,255,255,0.06)',
  },
  logoRow: { display: 'flex', alignItems: 'center', gap: '14px', marginBottom: '22px' },
  brandName: { fontSize: '19px', fontWeight: '700', color: '#1B1E24', lineHeight: '1.2' },
  brandSub:  { fontSize: '11px', fontWeight: '600', color: '#786F63', marginTop: '2px', textTransform: 'uppercase', letterSpacing: '0.6px' },

  greetWrap: { minHeight: '40px', borderTop: '1px solid #E5E0D8', borderBottom: '1px solid #E5E0D8', display: 'flex', alignItems: 'center', justifyContent: 'center', marginBottom: '24px' },
  greetText: { fontSize: '12.5px', fontWeight: '600', color: '#C2703D', textAlign: 'center', letterSpacing: '0.3px' },

  field: { marginBottom: '16px' },
  label: { display: 'block', fontSize: '11px', fontWeight: '700', color: '#1B1E24', marginBottom: '6px', textTransform: 'uppercase', letterSpacing: '0.6px' },
  input: {
    width: '100%', padding: '11px 14px', border: '1.5px solid #E5E0D8', borderRadius: '8px',
    fontSize: '14px', boxSizing: 'border-box', fontFamily: 'inherit', background: '#F7F5F1',
    color: '#1B1E24', transition: 'border-color 0.15s, background 0.15s',
  },
  eyeIcon: {
    position: 'absolute', right: '12px', top: '50%', transform: 'translateY(-50%)',
    cursor: 'pointer', fontSize: '16px', color: '#A39A8D', userSelect: 'none',
  },
  btn: {
    width: '100%', padding: '13px', marginTop: '6px',
    background: '#C2703D',
    color: '#FFFFFF', border: 'none', borderRadius: '8px',
    fontSize: '14px', fontWeight: '700', cursor: 'pointer',
    letterSpacing: '0.4px', textTransform: 'uppercase',
    boxShadow: '0 4px 14px rgba(194,112,61,0.35)',
  },
  errBox: {
    background: '#FAE3DF', color: '#8C2F24', border: '1px solid #F0BDB4',
    borderRadius: '8px', padding: '10px 14px', fontSize: '13px', fontWeight: '600',
    marginBottom: '18px', textAlign: 'center',
  },
  footer: { marginTop: '20px', textAlign: 'center' },
  forgotLink: { color: '#C2703D', cursor: 'pointer', fontSize: '13px', fontWeight: '600' },
  helpBox: {
    marginTop: '16px', padding: '11px 14px', background: '#F7F5F1', border: '1px solid #E5E0D8',
    borderRadius: '8px', fontSize: '12px', color: '#786F63', lineHeight: '1.7', textAlign: 'center',
  },
  code: {
    background: '#FFFFFF', border: '1px solid #E5E0D8', borderRadius: '4px',
    padding: '1px 6px', marginLeft: '6px', fontFamily: "'IBM Plex Mono', monospace",
    fontSize: '11.5px', color: '#C2703D', fontWeight: '600',
  },
  loginFootnote: {
    position: 'fixed', zIndex: 5, bottom: 0, left: 0, right: 0,
    boxSizing: 'border-box', padding: '8px 12px 9px', color: '#F2EDE5',
    background: 'rgba(20, 23, 28, 0.96)', borderTop: '1px solid rgba(194,112,61,0.55)',
    boxShadow: '0 -6px 22px rgba(0,0,0,0.22)',
  },
};

export default App;
