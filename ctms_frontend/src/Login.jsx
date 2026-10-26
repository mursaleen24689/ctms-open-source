import { useState } from 'react';
import { loginUser, saveToken } from './api';

function Login({ onLoginSuccess }) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [errorMessage, setErrorMessage] = useState('');

  // 2. Turn this into an "async" function so it can wait for the server's reply
  const handleLogin = async (e) => {
    e.preventDefault();
    setErrorMessage(''); // Clear out any old errors

    try {
      const response = await loginUser(email.trim(), password);
      const account = response.data;
      if (!account?.success || !account.token) {
        setErrorMessage(account?.error || 'Sign-in failed.');
        return;
      }
      saveToken(account.token);
      onLoginSuccess(account);

    } catch (error) {
      if (error.response) {
        setErrorMessage(error.response.data?.error || 'Sign-in failed.');
      } else {
        setErrorMessage('Cannot reach the CTMS server. Make sure XAMPP is running.');
      }
    }
  };

  return (
    <div style={styles.container}>
      <div style={styles.card}>
        <h2 style={styles.title}>CTMS Portal</h2>
        <p style={styles.subtitle}>Please sign in to your account</p>

        {/* If there's an error, show it in a red warning box */}
        {errorMessage && <div style={styles.errorBox}>{errorMessage}</div>}

        <form onSubmit={handleLogin}>
          <div style={styles.inputGroup}>
            <label style={styles.label}>Email Address</label>
            <input
              type="email"
              placeholder="Enter your email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              style={styles.input}
              required
            />
          </div>

          <div style={styles.inputGroup}>
            <label style={styles.label}>Password</label>
            <input
              type="password"
              placeholder="Enter your password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              style={styles.input}
              required
            />
          </div>

          <button type="submit" style={styles.button}>
            Sign In
          </button>
        </form>
      </div>
    </div>
  );
}

const styles = {
  container: {
    display: 'flex',
    justifyContent: 'center',
    alignItems: 'center',
    height: '100vh',
    backgroundColor: '#f3f4f6',
    fontFamily: 'sans-serif',
  },
  card: {
    backgroundColor: '#ffffff',
    padding: '40px',
    borderRadius: '10px',
    boxShadow: '0 4px 6px rgba(0, 0, 0, 0.1)',
    width: '100%',
    maxWidth: '400px',
  },
  title: {
    margin: '0 0 10px 0',
    color: '#1f2937',
    textAlign: 'center',
    fontSize: '28px',
  },
  subtitle: {
    margin: '0 0 25px 0',
    color: '#6b7280',
    textAlign: 'center',
    fontSize: '14px',
  },
  errorBox: {
    backgroundColor: '#fee2e2',
    color: '#dc2626',
    padding: '10px',
    borderRadius: '5px',
    marginBottom: '20px',
    fontSize: '14px',
    textAlign: 'center',
    border: '1px solid #fca5a5',
    fontWeight: '600'
  },
  inputGroup: {
    marginBottom: '20px',
  },
  label: {
    display: 'block',
    marginBottom: '5px',
    color: '#4b5563',
    fontSize: '14px',
    fontWeight: '600',
  },
  input: {
    width: '100%',
    padding: '10px',
    borderRadius: '5px',
    border: '1px solid #d1d5db',
    fontSize: '16px',
    boxSizing: 'border-box',
  },
  button: {
    width: '100%',
    padding: '12px',
    backgroundColor: '#2563eb',
    color: '#ffffff',
    border: 'none',
    borderRadius: '5px',
    fontSize: '16px',
    fontWeight: '600',
    cursor: 'pointer',
    marginTop: '10px',
  }
};

export default Login;
