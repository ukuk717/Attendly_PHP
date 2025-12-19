(() => {
  const supportsPasskey = !!(window.PublicKeyCredential && typeof PublicKeyCredential === 'function');
  const isSecureContext = window.isSecureContext === true;
  const isLocalhost = ['localhost', '127.0.0.1'].includes(window.location.hostname);
  const secureAllowed = isSecureContext || isLocalhost;

  const statusEls = document.querySelectorAll('[data-passkey-status]');
  const setStatus = (message, type = '') => {
    statusEls.forEach((el) => {
      el.textContent = message || '';
      el.className = `passkey-status ${type}`.trim();
    });
  };

  const getCsrfToken = () => {
    const hidden = document.querySelector('#passkey-csrf');
    if (hidden && hidden.value) {
      return hidden.value;
    }
    const input = document.querySelector('input[name="csrf_token"]');
    return input && input.value ? input.value : '';
  };

  const bufferToBase64url = (buffer) => {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.length; i += 1) {
      binary += String.fromCharCode(bytes[i]);
    }
    const base64 = btoa(binary);
    return base64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
  };

  const base64urlToBuffer = (value) => {
    const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
    const padLength = (4 - (base64.length % 4)) % 4;
    const padded = `${base64}${'='.repeat(padLength)}`;
    const binary = atob(padded);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i += 1) {
      bytes[i] = binary.charCodeAt(i);
    }
    return bytes.buffer;
  };

  const normalizePublicKey = (publicKey) => {
    const options = { ...publicKey };
    options.challenge = base64urlToBuffer(publicKey.challenge);
    if (publicKey.user && publicKey.user.id) {
      options.user = { ...publicKey.user, id: base64urlToBuffer(publicKey.user.id) };
    }
    if (Array.isArray(publicKey.excludeCredentials)) {
      options.excludeCredentials = publicKey.excludeCredentials.map((cred) => ({
        ...cred,
        id: base64urlToBuffer(cred.id),
      }));
    }
    if (Array.isArray(publicKey.allowCredentials)) {
      options.allowCredentials = publicKey.allowCredentials.map((cred) => ({
        ...cred,
        id: base64urlToBuffer(cred.id),
      }));
    }
    return options;
  };

  const postJson = async (url, payload) => {
    const csrfToken = getCsrfToken();
    if (!csrfToken) {
      return { ok: false, error: 'csrf_missing' };
    }
    const res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken,
      },
      body: JSON.stringify(payload || {}),
    });
    let data = {};
    try {
      data = await res.json();
    } catch (_) {
      data = {};
    }
    if (!res.ok) {
      return { ok: false, status: res.status, ...data };
    }
    return { ok: true, ...data };
  };

  const formatError = (error) => {
    if (error === 'rate_limited') {
      return 'リクエストが多すぎます。しばらく待ってから再試行してください。';
    }
    if (error === 'reauth_required') {
      return 'セキュリティ保護のため、再度ログインしてください。';
    }
    if (error === 'csrf_missing') {
      return 'ページを再読み込みしてから再試行してください。';
    }
    if (error === 'not_available') {
      return 'パスキーでのログインが利用できません。';
    }
    return 'パスキーの処理に失敗しました。';
  };

  const loginButton = document.querySelector('[data-passkey-login]');
  if (loginButton) {
    if (!supportsPasskey || !secureAllowed) {
      loginButton.disabled = true;
      const message = !supportsPasskey
        ? 'このブラウザではパスキーを利用できません。'
        : 'HTTPS 環境でパスキーを利用できます。';
      setStatus(message, 'error');
    } else {
      loginButton.addEventListener('click', async () => {
        setStatus('パスキーを呼び出しています…');
        loginButton.disabled = true;
        try {
          const emailInput = document.querySelector('#email');
          const email = emailInput && emailInput.value ? emailInput.value.trim() : '';
          const optionsResponse = await postJson('/passkeys/login/options', { email });
          if (!optionsResponse.ok || !optionsResponse.publicKey) {
            setStatus(formatError(optionsResponse.error), 'error');
            loginButton.disabled = false;
            return;
          }
          const publicKey = normalizePublicKey(optionsResponse.publicKey);
          const credential = await navigator.credentials.get({ publicKey });
          if (!credential) {
            setStatus('パスキーの取得に失敗しました。', 'error');
            loginButton.disabled = false;
            return;
          }
          const payload = {
            id: credential.id,
            rawId: bufferToBase64url(credential.rawId),
            type: credential.type,
            response: {
              clientDataJSON: bufferToBase64url(credential.response.clientDataJSON),
              authenticatorData: bufferToBase64url(credential.response.authenticatorData),
              signature: bufferToBase64url(credential.response.signature),
              userHandle: credential.response.userHandle ? bufferToBase64url(credential.response.userHandle) : '',
            },
          };
          const verifyResponse = await postJson('/passkeys/login/verify', payload);
          if (!verifyResponse.ok) {
            setStatus(formatError(verifyResponse.error), 'error');
            loginButton.disabled = false;
            return;
          }
          const redirect = verifyResponse.redirect || '/dashboard';
          window.location.assign(redirect);
        } catch (err) {
          if (err && err.name === 'AbortError') {
            setStatus('パスキーの操作を中断しました。', 'error');
          } else {
            setStatus('パスキーでログインできませんでした。', 'error');
          }
          loginButton.disabled = false;
        }
      });
    }
  }

  const registerButton = document.querySelector('[data-passkey-register]');
  if (registerButton) {
    if (!supportsPasskey || !secureAllowed) {
      registerButton.disabled = true;
      const message = !supportsPasskey
        ? 'このブラウザではパスキーを登録できません。'
        : 'HTTPS 環境でパスキーを登録できます。';
      setStatus(message, 'error');
    } else {
      registerButton.addEventListener('click', async () => {
        setStatus('パスキーを登録しています…');
        registerButton.disabled = true;
        try {
          const optionsResponse = await postJson('/passkeys/registration/options');
          if (!optionsResponse.ok || !optionsResponse.publicKey) {
            if (optionsResponse.error === 'reauth_required') {
              window.location.assign('/login');
              return;
            }
            setStatus(formatError(optionsResponse.error), 'error');
            registerButton.disabled = false;
            return;
          }
          const publicKey = normalizePublicKey(optionsResponse.publicKey);
          const credential = await navigator.credentials.create({ publicKey });
          if (!credential) {
            setStatus('パスキーの登録に失敗しました。', 'error');
            registerButton.disabled = false;
            return;
          }
          const labelInput = document.querySelector('#passkey-label');
          const label = labelInput && labelInput.value ? labelInput.value.trim() : '';
          const transports =
            credential.response.getTransports && typeof credential.response.getTransports === 'function'
              ? credential.response.getTransports()
              : [];
          const payload = {
            id: credential.id,
            rawId: bufferToBase64url(credential.rawId),
            type: credential.type,
            label,
            transports,
            response: {
              clientDataJSON: bufferToBase64url(credential.response.clientDataJSON),
              attestationObject: bufferToBase64url(credential.response.attestationObject),
            },
          };
          const verifyResponse = await postJson('/passkeys/registration/verify', payload);
          if (!verifyResponse.ok) {
            if (verifyResponse.error === 'reauth_required') {
              window.location.assign('/login');
              return;
            }
            setStatus(formatError(verifyResponse.error), 'error');
            registerButton.disabled = false;
            return;
          }
          window.location.reload();
        } catch (err) {
          if (err && err.name === 'AbortError') {
            setStatus('パスキーの登録を中断しました。', 'error');
          } else {
            setStatus('パスキーの登録に失敗しました。', 'error');
          }
          registerButton.disabled = false;
        }
      });
    }
  }
})();
