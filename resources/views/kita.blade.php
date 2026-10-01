<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<link rel="stylesheet" href="{{ asset('styles.css') }}?v={{ filemtime(public_path('styles.css')) }}">
<script src="{{ asset('support.js') }}"></script>
<title>KITA | Retail Management</title>
</head>
<body>
@verbatim
<x-dc>

<sc-if value="{{ showLogin }}" hint-placeholder-val="{{ false }}">
<div class="auth-page">
  <div class="auth-card auth-card--login">
    <div class="auth-card__bar"></div>
    <div class="auth-card__body">
      <div class="auth-header">
        @endverbatim<img src="{{ asset('images/kita-logo.jpg') }}" alt="KITA - Money. Managed. Smarter." class="kita-logo" width="200" height="200">@verbatim
        <div class="auth-eyebrow">YOUR RETAIL WORKSPACE</div>
        <h1 class="auth-title">Welcome back</h1>
        <div class="auth-subtitle">Sign in with your email and password to open your dashboard.</div>
      </div>

      <form class="form-stack" onSubmit="{{ doLogin }}">
        <div>
          <label class="form-label" for="login-email">{{ loginIdentifierLabel }}</label>
          <input id="login-email" class="form-input" type="email" autocomplete="email" value="{{ loginUsername }}" onChange="{{ onUsernameChange }}" onKeyDown="{{ onLoginKeyDown }}" placeholder="{{ loginIdentifierPlaceholder }}"/>
        </div>
        <div>
          <label class="form-label" for="login-password">Password</label>
          <input id="login-password" class="form-input" type="password" autocomplete="current-password" value="{{ loginPassword }}" onChange="{{ onPasswordChange }}" onKeyDown="{{ onLoginKeyDown }}" placeholder="Password"/>
        </div>
        <sc-if value="{{ loginError }}"><div class="alert" role="alert">{{ loginError }}</div></sc-if>
        <sc-if value="{{ dataError }}"><div class="alert">Database error: {{ dataError }}</div></sc-if>
        <sc-if value="{{ loginLocked }}"><div class="alert">Account locked after repeated failed attempts. Try again in 30s.</div></sc-if>
        <button type="submit" class="primary-button" disabled="{{ loginPending }}" aria-busy="{{ loginPending }}" onClick="{{ doLogin }}">{{ loginButtonLabel }}</button>
      </form>
    </div>
  </div>
</div>
</sc-if>

<sc-if value="{{ showOtp }}" hint-placeholder-val="{{ false }}">
<div class="auth-page">
  <div class="auth-card auth-card--otp">
    <div class="auth-card__bar"></div>
    <div class="auth-card__body auth-card__body--center">
      <div class="auth-header">
        @endverbatim<img src="{{ asset('images/kita-logo.jpg') }}" alt="KITA - Money. Managed. Smarter." class="kita-logo" width="200" height="200">@verbatim
        <div class="auth-title">Verify your sign-in</div>
        <div class="auth-subtitle">Enter the 6-digit code sent to {{ otpEmail }}.</div>
      </div>
      <form class="form-stack" onSubmit="{{ verifyOtp }}">
        <input aria-label="Six-digit verification code" class="form-input otp-input" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" value="{{ otpCode }}" onChange="{{ onOtpChange }}" placeholder="000000"/>
        <sc-if value="{{ loginError }}"><div class="alert" role="alert">{{ loginError }}</div></sc-if>
        <button type="submit" class="primary-button" disabled="{{ loginPending }}" aria-busy="{{ loginPending }}">{{ otpButtonLabel }}</button>
        <button type="button" class="link-button" disabled="{{ loginPending }}" onClick="{{ backToPassword }}">Back to password login</button>
      </form>
    </div>
  </div>
</div>
</sc-if>

<sc-if value="{{ showApp }}" hint-placeholder-val="{{ false }}">
<div class="{{ appShellClass }}" onKeyDown="{{ onShellKeyDown }}">
  <sc-if value="{{ sidebarOpen }}"><button class="sidebar-backdrop" tabindex="-1" aria-label="Close navigation" onClick="{{ closeSidebar }}"></button></sc-if>
  <aside id="app-sidebar" class="{{ sidebarClass }}" aria-label="Main navigation">
    <div class="sidebar-brand">
      @endverbatim<img src="{{ asset('images/kita-logo.jpg') }}" alt="KITA - Money. Managed. Smarter." class="kita-logo" width="200" height="200">@verbatim
      <button class="drawer-close" aria-label="Close navigation" onClick="{{ closeSidebar }}">&#215;</button>

    </div>

    <div class="sidebar-nav">
      <sc-for list="{{ navGroups }}" as="grp" hint-placeholder-count="3">
        <div class="nav-group">
          <sc-if value="{{ grp.label }}"><div class="nav-group__label">{{ grp.label }}</div></sc-if>
          <sc-for list="{{ grp.items }}" as="it" hint-placeholder-count="2">
            <button type="button" class="{{ it.className }}" aria-current="{{ it.current }}" onClick="{{ it.go }}"><span class="nav-icon">{{ it.icon }}</span><span>{{ it.label }}</span></button>
          </sc-for>
        </div>
      </sc-for>
    </div>

    <div class="sidebar-user">
      <div class="sidebar-user__label">Signed in as</div>
      <div class="sidebar-user__name">{{ currentUserName }}</div>
      <div class="user-role">{{ roleLabel }}</div>
      <button class="logout-button" onClick="{{ logout }}">Log Out</button>
    </div>
  </aside>

  <main class="main-shell" inert="{{ navigationInert }}" aria-hidden="{{ navigationHidden }}">
    <header class="topbar">
      <div class="topbar-heading"><button id="sidebar-toggle" class="menu-toggle" aria-label="Open navigation" aria-controls="app-sidebar" aria-expanded="{{ sidebarOpen }}" onClick="{{ toggleSidebar }}">&#9776;</button><div><div class="topbar-eyebrow">KITA / {{ screenGroup }}</div><div class="topbar-title">{{ screenTitle }}</div></div></div>
      <div class="topbar-actions">
        <div class="user-summary"><span class="user-avatar">{{ roleIcon }}</span><div><div class="user-name">{{ currentUserName }}</div><div class="user-role">{{ roleLabel }}</div></div></div>
        <sc-if value="{{ showMgrBell }}" hint-placeholder-val="{{ false }}">
          <div class="notif-wrap">
            <button class="notif-button" aria-label="Notifications" aria-controls="notification-panel" aria-expanded="{{ mgrNotifOpen }}" onClick="{{ toggleMgrNotif }}"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"></path></svg>
              <sc-if value="{{ mgrNotifUnreadCount }}" hint-placeholder-val="{{ false }}"><span class="notif-badge">{{ mgrNotifUnreadCount }}</span></sc-if>
            </button>
            <sc-if value="{{ mgrNotifOpen }}" hint-placeholder-val="{{ false }}">
              <div class="notif-menu" id="notification-panel" role="region" aria-label="Your notifications">
                <div class="notif-toolbar"><strong>Notifications</strong><button type="button" onClick="{{ closeNotifications }}" aria-label="Close notifications">&#215;</button></div>
                <div class="notif-toolbar"><button type="button" onClick="{{ markAllNotifications }}">Mark all as read</button><button type="button" onClick="{{ retryNotifications }}">Refresh</button></div>
                <sc-if value="{{ notificationLoading }}"><div class="empty-state loading-status" role="status"><span class="loading-spinner" aria-hidden="true"></span>Loading notifications...</div></sc-if>
                <sc-if value="{{ notificationError }}"><div class="alert" role="alert">{{ notificationError }}</div></sc-if>
                <sc-for list="{{ mgrNotifItems }}" as="n" hint-placeholder-count="2">
                  <button type="button" class="{{ n.className }}" onClick="{{ n.onClick }}">
                    <div class="notif-item__type">{{ n.type }}</div>
                    <div class="notif-item__message">{{ n.message }}</div>
                    <div class="notif-item__time">{{ n.time }}</div>
                  </button>
                </sc-for>
                <sc-if value="{{ mgrNotifEmpty }}" hint-placeholder-val="{{ false }}"><div class="empty-state">No notifications yet.</div></sc-if>
                <sc-if value="{{ notificationHasMore }}"><button type="button" class="notif-more" onClick="{{ loadMoreNotifications }}">Load older notifications</button></sc-if>
              </div>
            </sc-if>
          </div>
        </sc-if>
      </div>
    </header>

    <section class="content-area" aria-label="Page content" aria-busy="{{ dataLoading }}">
      <sc-if value="{{ dataLoading }}"><div class="loading-banner" role="status"><span class="loading-spinner" aria-hidden="true"></span> {{ dataLoadingLabel }}</div></sc-if>
      <sc-if value="{{ dataError }}"><div class="alert" role="alert">{{ dataError }}</div></sc-if>
      {{ activeScreen }}
    </section>
  </main>

  <div class="toast-wrap" aria-live="polite">
    <sc-for list="{{ toasts }}" as="t" hint-placeholder-count="0">
      <div class="toast" style="{{ t.style }}">{{ t.message }}</div>
    </sc-for>
  </div>
</div>
</sc-if>

</x-dc>
@endverbatim
<script>window.KITA_DATA_URL = "{{ route('kita.data', [], false) }}";</script>
<script>
window.KITA_AUTH = {
  loginError: {{ Illuminate\Support\Js::from(session('login_error', '')) }},
  user: {{ Illuminate\Support\Js::from(auth()->user()?->only(['id', 'name', 'email', 'role'])) }},
  loginUrl: "{{ route('login', [], false) }}",
  transactionUrl: "{{ route('transactions.store', [], false) }}",
  paymongoUrl: "{{ route('paymongo.checkout', [], false) }}",
  logoutUrl: "{{ route('logout', [], false) }}",
  csrfToken: "{{ csrf_token() }}"
};
</script>
<script type="text/x-dc" data-dc-script src="{{ asset('app.js') }}?v={{ filemtime(public_path('app.js')) }}"></script>
</body>
</html>
