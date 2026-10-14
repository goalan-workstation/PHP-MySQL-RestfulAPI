// assets/js/contactus.js
// contactus.html：載入 top + body_contactus.html + footer，表單 POST 寫入 contact_us

function fill(s, m) { Object.keys(m).forEach(function (k) { s = s.split('{{' + k + '}}').join(m[k]); }); return s; }

var demo = {
  WEB_TITLE: 'ALAN_PHP',
  WEB_DESCRIPTION: '',
  HOME_URL: 'index.html',
  NEWS_LIST_URL: 'index.html?news',
  ACTIVITY_LIST_URL: 'index.html?activity',
  ALBUM_LIST_URL: 'index.html?album',
  PRODUCTS_LIST_URL: 'index.html?products',
  MEMBER_URL: 'index.html?member',
  ABOUT_URL: 'aboutus.html',
  CONTACT_URL: 'contactus.html',
  CART_URL: 'shopping_cart.html',
  LOGIN_URL: 'index.html?member',
  LOGOUT_URL: 'skin/body_member_list.php?action=logout',
  MEMBER_EDIT_URL: 'index.html?member&edit',
  CART_ICON: 'assets/img/Duck-ai-image-2026-10-07-15-02.jpeg',
  AVATAR_ICON: 'assets/img/Duck-ai-image-2026-10-07-15-04.jpeg',
  AVATAR_STYLE: 'display:none;',
  CART_STYLE: 'display:inline-block;',
  CART_COUNT: '0',
  BANNER_SRC: '',
  BANNER_STYLE: 'display:none;',
  CSRF_TOKEN: '',
  MEMBER_NAME: '',
  YEAR: String(new Date().getFullYear()),
  LOGGED_IN: '0',
  BLOCKS_JSON: '{}'
};

function renderChrome(topTpl, footTpl, api) {
  var live = Object.assign({}, demo, {
    WEB_TITLE: api.title || demo.WEB_TITLE,
    WEB_DESCRIPTION: api.description || '',
    PRODUCTS_LIST_URL: 'index.html?products',
    AVATAR_STYLE: (api.blocks && api.blocks.member) ? 'display:block;' : 'display:none;',
    CART_STYLE: (api.blocks && api.blocks.product) ? 'display:inline-block;' : 'display:none;',
    CART_COUNT: String(api.cart_count || 0),
    BANNER_SRC: api.banner || '',
    BANNER_STYLE: api.banner ? 'display:block;' : 'display:none;',
    CSRF_TOKEN: api.csrf_token || '',
    MEMBER_NAME: api.member_name || '',
    LOGGED_IN: api.logged_in ? '1' : '0',
    BLOCKS_JSON: JSON.stringify(api.blocks || {})
  });
  var topHtml = fill(topTpl, live);
  document.getElementById('slot-top').innerHTML = topHtml;
  document.getElementById('slot-footer').innerHTML = fill(footTpl, live).replace(/<script>[\s\S]*?<\/script>/, '');
  if (api.style_option !== undefined) document.documentElement.setAttribute('data-theme', api.style_option);
  initTopDropdown(api);
  applyBlockNav(api);
  return live;
}

// ── 依 api.blocks 隱藏上方導覽列中未啟用的區塊連結 ──
function applyBlockNav(api) {
  if (!api.blocks) return;
  Array.prototype.forEach.call(document.querySelectorAll('#topNav a[data-block]'), function (a) {
    if (api.blocks[a.getAttribute('data-block')] !== true) a.style.display = 'none';
  });
}

// ── 右上方 avatar 下拉選單：登入狀態切換 + 登入驗證（同會員頁規則）+ 登出 ──
function initTopDropdown(api) {
  var ls = document.getElementById('loginSection'), os = document.getElementById('logoutSection');
  if (!ls || !os) return;
  var loggedIn = !!api.logged_in;
  ls.style.display = loggedIn ? 'none' : 'block';
  os.style.display = loggedIn ? 'block' : 'none';
  var form = document.getElementById('loginForm');
  if (form && !loggedIn) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var msgEl = document.getElementById('loginMsg');
      var uid = form.user_id ? form.user_id.value.trim() : '';
      var pwd = form.user_pwd ? form.user_pwd.value : '';
      if (!/^[A-Za-z0-9._-]{4,20}$/.test(uid)) {
        if (msgEl) { msgEl.style.color = 'red'; msgEl.textContent = '帳號格式不正確（4-20 字元，僅英數及 ._ -）。'; }
        return;
      }
      if (pwd.length < 6) {
        if (msgEl) { msgEl.style.color = 'red'; msgEl.textContent = '密碼至少需要 6 個字元。'; }
        return;
      }
      fetch('skin/body_member_list.php?action=login', { method: 'POST', body: new URLSearchParams(new FormData(form)) })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j.success) { location.reload(); }
          else { if (msgEl) { msgEl.style.color = 'red'; msgEl.textContent = j.error || '發生錯誤。'; } }
        })
        .catch(function (err) { if (msgEl) { msgEl.style.color = 'red'; msgEl.textContent = '連線失敗：' + err.message; } });
    });
  }
  var btn = document.getElementById('logoutBtn');
  if (btn && loggedIn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      var fd = new URLSearchParams();
      fd.append('csrf_token', api.csrf_token || '');
      fetch('skin/body_member_list.php?action=logout', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j.success) { location.reload(); }
          else { alert((j.error || '登出失敗。') + ' 請稍後再試。'); }
        })
        .catch(function () { alert('連線失敗，無法登出。請稍後再試。'); });
    });
  }
}

Promise.all([
  fetch('skin/top.html').then(function (r) { if (!r.ok) throw new Error('top 載入失敗'); return r.text(); }),
  fetch('skin/footer.html').then(function (r) { if (!r.ok) throw new Error('footer 載入失敗'); return r.text(); }),
  fetch('skin/body_contactus.html').then(function (r) { if (!r.ok) throw new Error('聯絡表單載入失敗'); return r.text(); }),
  fetch('skin/body_contactus.php').then(function (r) { if (!r.ok) throw new Error('API ' + r.status); return r.json(); })
    .then(function (j) { if (!j || !j.success) throw new Error('API 回傳失敗'); return j; })
]).then(function (res) {
  var live = renderChrome(res[0], res[1], res[3]);
  var vars = Object.assign({}, live, {
    MSG: '',
    FORM_ACTION: 'skin/body_contactus.php',
    NAME: '',
    EMAIL: '',
    TITLE: '',
    CONTENT: '',
    CONTACT_INFO: ''
  });
  document.getElementById('slot-body').innerHTML = fill(res[2], vars);

  var form = document.querySelector('.contact-form');
  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var status = document.getElementById('formStatus');
      var msg = document.querySelector('.form-message');
      if (status) { status.style.color = 'var(--text-secondary)'; status.textContent = '送出中…'; }
      fetch('skin/body_contactus.php', { method: 'POST', body: new FormData(form) })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j.success) {
            if (msg) { msg.style.color = 'var(--primary)'; msg.textContent = j.message || '訊息已送出。'; }
            if (status) status.textContent = '';
            form.reset();
          } else {
            if (status) { status.style.color = 'red'; status.textContent = j.error || '送出失敗。'; }
          }
        })
        .catch(function (err) {
          if (status) { status.style.color = 'red'; status.textContent = '連線失敗：' + err.message; }
        });
    });
  }
}).catch(function (err) {
  document.getElementById('slot-body').innerHTML = '<p style="padding:24px;color:red;">' + err.message + '</p>';
});
