// assets/js/shopping_cart.js
// shopping_cart.html：購物車清單（session 暫存：訂單編號 + 品項 + 數量改/移除/清空）

function fill(s, m) { Object.keys(m).forEach(function (k) { s = s.split('{{' + k + '}}').join(m[k]); }); return s; }
function expand(tpl, row, items) {
  var re = new RegExp('<!-- BEGIN ' + row + ' -->[\\s\\S]*?<!-- END ' + row + ' -->');
  var m = tpl.match(re);
  if (!m) return tpl;
  var inner = m[0].replace(/<!-- (BEGIN|END) [^>]+ -->/g, '');
  var out = items.map(function (it) {
    var r = inner;
    Object.keys(it).forEach(function (k) { r = r.split('{{' + k + '}}').join(it[k]); });
    return r;
  }).join('\n');
  return tpl.replace(re, out);
}

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
  document.getElementById('slot-top').innerHTML = fill(topTpl, live);
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

function postCart(api, action, extra, okMsg) {
  var fd = new FormData();
  fd.append('action', action);
  fd.append('csrf_token', api.csrf_token || '');
  Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
  return fetch('skin/body_shopping_cart.php', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      if (!j.success) throw new Error(j.error || '操作失敗。');
      if (okMsg) okMsg(j.message || '已更新。');
      return j;
    });
}

function renderCart(tpl, live, api) {
  var items = (api.cart_items || []).map(function (r) {
    return {
      PRODUCT_ID: String(r.product_id),
      PRODUCT_PIC: r.pic || '',
      PRODUCT_TITLE: r.title || '',
      DETAIL_URL: r.detail_url || '#',
      PRICE: String(r.price || 0),
      QTY: String(r.qty || 0),
      SUBTOTAL: String(r.subtotal || 0)
    };
  });
  var total = 0;
  (api.cart_items || []).forEach(function (r) { total += (r.subtotal || 0); });
  var vars = Object.assign({}, live, {
    CART_CODE: api.cart_code || '（尚未產生，加入產品後自動產生）',
    MSG: api.message || '',
    EMPTY_MSG: items.length ? '' : '購物車目前是空的，去挑幾件產品吧！',
    TOTAL: String(total),
    CSRF_TOKEN: api.csrf_token || '',
    ORDER_NAME: api.order_defaults ? (api.order_defaults.name || '') : '',
    ORDER_EMAIL: api.order_defaults ? (api.order_defaults.email || '') : '',
    ORDER_PHONE: api.order_defaults ? (api.order_defaults.phone || '') : '',
    ORDER_ADDRESS: api.order_defaults ? (api.order_defaults.address || '') : '',
    PRODUCTS_LIST_URL: 'index.html?products'
  });
  document.getElementById('slot-body').innerHTML = fill(expand(tpl, 'ROW_ITEM', items), vars);

  // 未登入：結帳區提示登入
  if (!api.logged_in) {
    var checkoutArea = document.getElementById('checkoutArea');
    if (checkoutArea) {
      checkoutArea.innerHTML = '<h3 style="margin:0 0 12px;font-size:17px;">結帳資料</h3>'
        + '<p style="font-size:14px;color:var(--text-secondary);">請先登入會員才能結帳（訂單將綁定您的會員帳號）。</p>'
        + '<p style="margin-top:12px;"><a class="button button-primary" href="index.html?member">前往會員登入</a></p>';
    }
  } else {
    // 已登入：送出結帳
    var checkoutForm = document.getElementById('checkoutForm');
    if (checkoutForm) {
      checkoutForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var msg = document.getElementById('checkoutMsg');
        var btn = checkoutForm.querySelector('button[type="submit"]');
        var old = btn ? btn.textContent : '';
        if (btn) btn.textContent = '結帳中…';
        if (msg) { msg.style.color = 'var(--text-secondary)'; msg.textContent = '正在寫入訂單…'; }
        var fd = new FormData(checkoutForm);
        fd.append('action', 'checkout');
        fetch('skin/body_shopping_cart.php', { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (!j.success) throw new Error(j.error || '結帳失敗。');
            if (msg) { msg.style.color = 'var(--primary)'; msg.textContent = j.message || '結帳成功。'; }
            if (j.order) {
              alert('結帳成功！訂單編號：' + j.order.cart_code + '\n共 ' + j.order.item_count + ' 項，總計 NT$ ' + j.order.total);
            }
            setTimeout(function () { location.reload(); }, 800);
          })
          .catch(function (err) {
            if (btn) btn.textContent = old;
            if (msg) { msg.style.color = 'red'; msg.textContent = err.message; }
          });
      });
    }
  }

  // 數量更新
  Array.prototype.forEach.call(document.querySelectorAll('[data-update-for]'), function (btn) {
    btn.addEventListener('click', function () {
      var pid = btn.getAttribute('data-update-for');
      var input = document.querySelector('[data-qty-for="' + pid + '"]');
      var qty = input ? input.value : '1';
      postCart(api, 'update', { product_id: pid, qty: qty }, function () { location.reload(); })
        .catch(function (err) { alert(err.message); });
    });
  });
  // 移除單品
  Array.prototype.forEach.call(document.querySelectorAll('[data-remove-for]'), function (btn) {
    btn.addEventListener('click', function () {
      if (!confirm('確定要移除這件產品嗎？')) return;
      postCart(api, 'remove', { product_id: btn.getAttribute('data-remove-for') }, function () { location.reload(); })
        .catch(function (err) { alert(err.message); });
    });
  });
  // 清空
  var clearBtn = document.getElementById('clearCartBtn');
  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      if (!confirm('確定要清空購物車嗎？')) return;
      postCart(api, 'clear', {}, function () { location.reload(); })
        .catch(function (err) { alert(err.message); });
    });
  }
}

Promise.all([
  fetch('skin/top.html').then(function (r) { if (!r.ok) throw new Error('top 載入失敗'); return r.text(); }),
  fetch('skin/footer.html').then(function (r) { if (!r.ok) throw new Error('footer 載入失敗'); return r.text(); }),
  fetch('skin/body_shopping_cart.html').then(function (r) { if (!r.ok) throw new Error('購物車版型載入失敗'); return r.text(); }),
  fetch('skin/body_shopping_cart.php').then(function (r) { if (!r.ok) throw new Error('API ' + r.status); return r.json(); })
    .then(function (j) { if (!j || !j.success) throw new Error('API 回傳失敗'); return j; })
]).then(function (res) {
  var live = renderChrome(res[0], res[1], res[3]);
  renderCart(res[2], live, res[3]);
}).catch(function (err) {
  document.getElementById('slot-body').innerHTML = '<p style="padding:24px;color:red;">' + err.message + '</p>';
});
