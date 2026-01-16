(() => {
  const supportsPasskey = !!(window.PublicKeyCredential && typeof PublicKeyCredential === 'function');
  const isSecureContext = window.isSecureContext === true;
  const isLocalhost = ['localhost', '127.0.0.1'].includes(window.location.hostname);
  const secureAllowed = isSecureContext || isLocalhost;
  const PASSKEY_TIMEOUT_MS = 15000;
  const PASSKEY_PROMPT_HIDE_MS = 30 * 24 * 60 * 60 * 1000;

  const recommendationCard = document.querySelector('[data-passkey-recommendation]');
  if (recommendationCard) {
    const userId = recommendationCard.getAttribute('data-user-id') || '0';
    const hasPasskey = recommendationCard.getAttribute('data-has-passkey') === '1';
    const dismissButton = recommendationCard.querySelector('[data-passkey-dismiss]');
    const removeCard = () => {
      recommendationCard.remove();
    };

    if (hasPasskey || !supportsPasskey || !secureAllowed) {
      removeCard();
    } else {
      let dismissedAt = 0;
      try {
        const raw = window.localStorage.getItem(`passkey_prompt_dismissed_${userId}`);
        dismissedAt = raw ? parseInt(raw, 10) : 0;
      } catch (_) {
        dismissedAt = 0;
      }
      if (dismissedAt && Number.isFinite(dismissedAt) && Date.now() - dismissedAt < PASSKEY_PROMPT_HIDE_MS) {
        removeCard();
      } else if (dismissButton) {
        dismissButton.addEventListener('click', () => {
          try {
            window.localStorage.setItem(`passkey_prompt_dismissed_${userId}`, String(Date.now()));
          } catch (_) {
            // ignore storage failures
          }
          removeCard();
        });
      }
    }
  }

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
    if (error === 'passkey_unavailable') {
      return 'パスキー機能を利用できません。管理者に確認してください。';
    }
    if (error === 'not_available') {
      return 'パスキーでのログインが利用できません。';
    }
    return 'パスキーの処理に失敗しました。';
  };

  const formatClientError = (err) => {
    if (!err) {
      return 'パスキーの処理に失敗しました。';
    }
    if (err.name === 'NotAllowedError') {
      return 'パスキーの操作がキャンセルされました。';
    }
    if (err.name === 'NotSupportedError') {
      return 'この端末ではパスキーを利用できません。';
    }
    if (err.name === 'SecurityError') {
      return 'URLとパスキー設定が一致しません。HTTPSまたは正しいホスト名でお試しください。';
    }
    if (err.name === 'InvalidStateError') {
      return 'この端末ではパスキーを登録できません。別の端末でお試しください。';
    }
    return 'パスキーの処理に失敗しました。';
  };

  const createPasskeyTimeout = (onTimeout) => {
    const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    const timer = window.setTimeout(() => {
      if (controller) {
        controller.abort();
      }
      onTimeout();
    }, PASSKEY_TIMEOUT_MS);
    return {
      controller,
      clear: () => {
        window.clearTimeout(timer);
      },
    };
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
        let finished = false;
        let timeout = null;
        const finish = (message, type = 'error') => {
          if (finished) {
            return;
          }
          finished = true;
          if (timeout) {
            timeout.clear();
          }
          loginButton.disabled = false;
          setStatus(message, type);
        };
        timeout = createPasskeyTimeout(() => {
          finish('パスキーの処理がタイムアウトしました。もう一度お試しください。', 'error');
        });
        try {
          const emailInput = document.querySelector('#email');
          const email = emailInput && emailInput.value ? emailInput.value.trim() : '';
          const optionsResponse = await postJson('/passkeys/login/options', { email });
          if (!optionsResponse.ok || !optionsResponse.publicKey) {
            finish(formatError(optionsResponse.error), 'error');
            return;
          }
          const publicKey = normalizePublicKey(optionsResponse.publicKey);
          const credential = await navigator.credentials.get({
            publicKey,
            ...(timeout.controller ? { signal: timeout.controller.signal } : {}),
          });
          if (!credential) {
            finish('パスキーの取得に失敗しました。', 'error');
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
            finish(formatError(verifyResponse.error), 'error');
            return;
          }
          timeout.clear();
          const redirect = verifyResponse.redirect || '/dashboard';
          window.location.assign(redirect);
        } catch (err) {
          if (err && err.name === 'AbortError') {
            finish('パスキーの処理がタイムアウトしました。もう一度お試しください。', 'error');
            return;
          }
          finish(formatClientError(err), 'error');
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
        let finished = false;
        let timeout = null;
        const finish = (message, type = 'error') => {
          if (finished) {
            return;
          }
          finished = true;
          if (timeout) {
            timeout.clear();
          }
          registerButton.disabled = false;
          setStatus(message, type);
        };
        timeout = createPasskeyTimeout(() => {
          finish('パスキーの処理がタイムアウトしました。もう一度お試しください。', 'error');
        });
        try {
          const optionsResponse = await postJson('/passkeys/registration/options');
          if (!optionsResponse.ok || !optionsResponse.publicKey) {
            if (optionsResponse.error === 'reauth_required') {
              timeout.clear();
              window.location.assign('/login');
              return;
            }
            finish(formatError(optionsResponse.error), 'error');
            return;
          }
          const publicKey = normalizePublicKey(optionsResponse.publicKey);
          const credential = await navigator.credentials.create({
            publicKey,
            ...(timeout.controller ? { signal: timeout.controller.signal } : {}),
          });
          if (!credential) {
            finish('パスキーの登録に失敗しました。', 'error');
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
              timeout.clear();
              window.location.assign('/login');
              return;
            }
            finish(formatError(verifyResponse.error), 'error');
            return;
          }
          timeout.clear();
          window.location.reload();
        } catch (err) {
          if (err && err.name === 'AbortError') {
            finish('パスキーの処理がタイムアウトしました。もう一度お試しください。', 'error');
            return;
          }
          finish(formatClientError(err), 'error');
        }
      });
    }
  }
})();
