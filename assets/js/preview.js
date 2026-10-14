// assets/js/preview.js
// index.html 主殼 JS：依 URL 參數切換 首頁 / 新聞列表 / 新聞內容

// ── 工具函式
function fill(s,m){Object.keys(m).forEach(function(k){s=s.split("{{"+k+"}}").join(m[k]);});return s;}
function expand(tpl,row,items){var re=new RegExp("<!-- BEGIN "+row+" -->[\\s\\S]*?<!-- END "+row+" -->");var m=tpl.match(re);if(!m)return tpl;var inner=m[0].replace(/<!-- (BEGIN|END) [^>]+ -->/g,"");var out=items.map(function(it){var r=inner;Object.keys(it).forEach(function(k){r=r.split("{{"+k+"}}").join(it[k]);});return r;}).join("\n");return tpl.replace(re,out);}
var PIC=function(s){return "https://picsum.photos/seed/"+s+"/600/400";};

// ── 示範資料
var demo={
  WEB_TITLE:"ALAN_PHP 示範站",
  WEB_DESCRIPTION:"靜態版型確認用：top + 首頁四區塊 + footer，色系之後套後台設定。",
  HOME_URL:"index.html",
  NEWS_LIST_URL:"index.html?news",
  ACTIVITY_LIST_URL:"index.html?activity",
  ALBUM_LIST_URL:"index.html?album",PRODUCTS_LIST_URL:"index.html?products",
  MEMBER_URL:"index.html?member",ABOUT_URL:"aboutus.html",CONTACT_URL:"contactus.html",CART_URL:"shopping_cart.html",
  LOGIN_URL:"index.html?member",LOGOUT_URL:"skin/body_member_list.php?action=logout",MEMBER_EDIT_URL:"index.html?member&edit",
  CART_ICON:"assets/img/Duck-ai-image-2026-10-07-15-02.jpeg",
  AVATAR_ICON:"assets/img/Duck-ai-image-2026-10-07-15-04.jpeg",
  AVATAR_STYLE:"display:block;",CART_STYLE:"display:inline-block;",CART_COUNT:"3",
  BANNER_SRC:"",BANNER_STYLE:"display:none;",
  CSRF_TOKEN:"preview",MEMBER_NAME:"訪客",
  YEAR:String(new Date().getFullYear()),EMPTY_MSG:"",LOGGED_IN:"0",
  BLOCKS_JSON:'{"news":true,"activity":true,"album":true,"product":true,"member":true}'
};

// ── API 資料映射
function mapNews(a){return (a||[]).map(function(r){return {DETAIL_URL:"index.html?news&id="+r.id,NEWS_PIC:r.pic||"",TITLE:r.title||"",TITLE_2:r.title_2||"",CREAT_TIME:(r.creat_time||"").slice(0,10)};});}
function mapAct(a){return (a||[]).map(function(r){return {DETAIL_URL:r.detail_url||"#",ACTIVITY_PIC:r.pic||"",TITLE:r.title||"",TITLE_2:r.title_2||"",CREAT_TIME:(r.creat_time||"").slice(0,10)};});}
function mapAlbum(a){return (a||[]).map(function(r){return {DETAIL_URL:r.detail_url||"#",ALBUM_PIC:r.pic||"",TITLE:r.title||"",PHOTO_COUNT:String(r.photo_count||0)};});}
function mapProd(a){return (a||[]).map(function(r){return {DETAIL_URL:r.detail_url||"#",ADD_CART_URL:"#",PRODUCT_PIC:r.pic||"",TITLE:r.title||"",TITLE_2:r.title_2||"",PRODUCT_PRICE:String(r.price||0),PRODUCT_NUM:String(r.product_num||0),PRODUCT_RENUM:String(r.product_renum||0)};});}
function mapProdList(a){return (a||[]).map(function(r){return {DETAIL_URL:r.detail_url||"#",ADD_CART_URL:"#",ADD_CART_PID:String(r.id||""),PRODUCT_PIC:r.pic||"",TITLE:r.title||"",TITLE_2:r.title_2||"",PRODUCT_PRICE:String(r.price||0),PRODUCT_NUM:String(r.product_num||0),PRODUCT_RENUM:String(r.product_renum||0)};});}

// ── API 載入
function loadSite(){
  return fetch("index.php?action=home")
    .then(function(r){if(!r.ok)throw new Error("API "+r.status);return r.json();})
    .then(function(j){if(!j||!j.success)throw new Error("API 回傳失敗");return j;});
}
function loadNews(url){
  return fetch(url)
    .then(function(r){if(!r.ok)throw new Error("API "+r.status);return r.json();})
    .then(function(j){if(!j||!j.success)throw new Error(j.error||"API error");return j;});
}

// ── 共用：渲染 top + footer
function renderChrome(topTpl,footTpl,api){
  var live=Object.assign({},demo,{
    WEB_TITLE:api.title||demo.WEB_TITLE,
    WEB_DESCRIPTION:api.description||demo.WEB_DESCRIPTION,
    NEWS_LIST_URL:"index.html?news",
    ACTIVITY_LIST_URL:"index.html?activity",
    ALBUM_LIST_URL:"index.html?album",
    PRODUCTS_LIST_URL:"index.html?products",
    MEMBER_URL:"index.html?member",
    MEMBER_EDIT_URL:"index.html?member&edit",
    AVATAR_STYLE:(api.blocks&&api.blocks.member)?"display:block;":"display:none;",
    CART_STYLE:(api.blocks&&api.blocks.product)?"display:inline-block;":"display:none;",
    CART_COUNT:String(api.cart_count||0),
    BANNER_SRC:api.banner||"",
    BANNER_STYLE:api.banner?"display:block;":"display:none;",
    CSRF_TOKEN:api.csrf_token||"",
    MEMBER_NAME:api.member_name||"",
    LOGGED_IN:api.logged_in?"1":"0",
    BLOCKS_JSON:JSON.stringify(api.blocks||{})
  });
  var topHtml=fill(topTpl,live);
  document.getElementById("slot-top").innerHTML=topHtml;
  document.getElementById("slot-footer").innerHTML=fill(footTpl,live).replace(/<script>[\s\S]*?<\/script>/,"");
  if(api.style_option!==undefined)document.documentElement.setAttribute("data-theme",api.style_option);
  var dd=document.getElementById("avatarDropdown"),av=document.getElementById("avatarIcon");
  if(av&&dd){
    av.addEventListener("click",function(e){e.stopPropagation();dd.style.display=dd.style.display==="none"?"block":"none";});
    document.addEventListener("click",function(e){if(!dd.contains(e.target))dd.style.display="none";});
  }
  initTopDropdown(api);
  applyBlockNav(api);
  return live;
}

// ── 依 api.blocks 隱藏上方導覽列中未啟用的區塊連結 ──
function applyBlockNav(api){
  if(!api.blocks)return;
  Array.prototype.forEach.call(document.querySelectorAll('#topNav a[data-block]'),function(a){
    if(api.blocks[a.getAttribute("data-block")]!==true)a.style.display="none";
  });
}

// ── 右上方 avatar 下拉選單：登入狀態切換 + 登入驗證（同會員頁規則）+ 登出 ──
function initTopDropdown(api){
  var ls=document.getElementById("loginSection"),os=document.getElementById("logoutSection");
  if(!ls||!os)return;
  var loggedIn=!!api.logged_in;
  ls.style.display=loggedIn?"none":"block";
  os.style.display=loggedIn?"block":"none";
  var form=document.getElementById("loginForm");
  if(form&&!loggedIn){
    form.addEventListener("submit",function(e){
      e.preventDefault();
      var msgEl=document.getElementById("loginMsg");
      var uid=form.user_id?form.user_id.value.trim():"";
      var pwd=form.user_pwd?form.user_pwd.value:"";
      if(!/^[A-Za-z0-9._-]{4,20}$/.test(uid)){
        if(msgEl){msgEl.style.color="red";msgEl.textContent="帳號格式不正確（4-20 字元，僅英數及 ._ -）。";}
        return;
      }
      if(pwd.length<6){
        if(msgEl){msgEl.style.color="red";msgEl.textContent="密碼至少需要 6 個字元。";}
        return;
      }
      fetch("skin/body_member_list.php?action=login",{method:"POST",body:new URLSearchParams(new FormData(form))})
        .then(function(r){return r.json();})
        .then(function(j){
          if(j.success){location.reload();}
          else{if(msgEl){msgEl.style.color="red";msgEl.textContent=j.error||"發生錯誤。";}}
        })
        .catch(function(err){if(msgEl){msgEl.style.color="red";msgEl.textContent="連線失敗："+err.message;}});
    });
  }
  var btn=document.getElementById("logoutBtn");
  if(btn&&loggedIn){
    btn.addEventListener("click",function(e){
      e.preventDefault();
      var fd=new URLSearchParams();fd.append("csrf_token",api.csrf_token||"");
      fetch("skin/body_member_list.php?action=logout",{method:"POST",body:fd})
        .then(function(r){return r.json();})
        .then(function(j){
          if(j.success){location.reload();}
          else{alert((j.error||"登出失敗。")+" 請稍後再試。");}
        })
        .catch(function(){alert("連線失敗，無法登出。請稍後再試。");});
    });
  }
}

// ── URL 參數判斷
var params=new URLSearchParams(location.search);
var isNews=params.get("news")!==null;
var isActivity=params.get("activity")!==null;
var isAlbum=params.get("album")!==null;
var isProducts=params.get("products")!==null;
var isMember=params.get("member")!==null;
if(isNews){
// NEWS MODE  index.html?news  或  index.html?news&id=X
  var newsId=params.get("id");
  var newsPage=params.get("page")||"1";
  var tplUrl=newsId?"skin/body_news_detail.html":"skin/body_news_list.html";
  var apiUrl=newsId
    ?"skin/body_news_list.php?id="+encodeURIComponent(newsId)
    :"skin/body_news_list.php?page="+encodeURIComponent(newsPage);
  Promise.all([
    fetch("skin/top.html").then(function(r){if(!r.ok)throw new Error("top 載入失敗");return r.text();}),
    fetch("skin/footer.html").then(function(r){if(!r.ok)throw new Error("footer 載入失敗");return r.text();}),
    fetch(tplUrl).then(function(r){if(!r.ok)throw new Error(tplUrl+" 載入失敗");return r.text();}),
    loadNews(apiUrl)
  ]).then(function(res){
    var top=res[0],foot=res[1],tpl=res[2],api=res[3];
    var live=renderChrome(top,foot,api);
    var bodyHtml;
    if(newsId){
      var d=api.detail||{};
      var dvars=Object.assign({},live,{
        TITLE:d.title||"",
        CREAT_TIME:(d.creat_time||"").slice(0,10),
        NEWS_PIC:d.pic||"",
        DESCRIPTION:(d.description||"").replace(/\n/g,"<br>"),
        BACK_URL:"index.html?news"
      });
      bodyHtml=fill(tpl,dvars);
    }else{
      var items=mapNews(api.list);
      var pageNav="";
      if(api.total_pages>1){
        for(var i=1;i<=api.total_pages;i++){
          pageNav+=i===api.page
            ?'<span style="padding:6px 14px;background:var(--primary);color:#fff;border-radius:8px;display:inline-block;min-width:36px;text-align:center;font-size:14px;">'+i+'</span>'
            :'<a href="index.html?news&page='+i+'" style="padding:6px 14px;border:1px solid var(--border);border-radius:8px;text-decoration:none;color:var(--primary);background:var(--surface);display:inline-block;min-width:36px;text-align:center;font-size:14px;">'+i+'</a>';
        }
      }
      var lvars=Object.assign({},live,{
        EMPTY_MSG:items.length?"":'<p style="text-align:center;color:var(--text-secondary);padding:24px;">目前沒有最新消息。</p>',
        PAGE_NAV:pageNav
      });
      bodyHtml=fill(expand(tpl,"ROW_NEWS",items),lvars);
    }
    document.getElementById("slot-body").innerHTML=bodyHtml;
    Array.prototype.forEach.call(document.querySelectorAll(".reveal"),function(el,i){
      setTimeout(function(){el.classList.add("visible");},60*i);
    });
  }).catch(function(err){
    document.getElementById("slot-body").innerHTML='<p style="padding:24px;color:red;">'+err.message+'</p>';
  });

}else if(isActivity){
// ACTIVITY MODE  index.html?activity  或  index.html?activity&id=X
  var actId=params.get("id");
  var actPage=params.get("page")||"1";
  var tplUrl=actId?"skin/body_activity_detail.html":"skin/body_activity_list.html";
  var apiUrl=actId
    ?"skin/body_activity_list.php?id="+encodeURIComponent(actId)
    :"skin/body_activity_list.php?page="+encodeURIComponent(actPage);
  Promise.all([
    fetch("skin/top.html").then(function(r){if(!r.ok)throw new Error("top 載入失敗");return r.text();}),
    fetch("skin/footer.html").then(function(r){if(!r.ok)throw new Error("footer 載入失敗");return r.text();}),
    fetch(tplUrl).then(function(r){if(!r.ok)throw new Error(tplUrl+" 載入失敗");return r.text();}),
    loadNews(apiUrl)
  ]).then(function(res){
    var top=res[0],foot=res[1],tpl=res[2],api=res[3];
    var live=renderChrome(top,foot,api);
    var bodyHtml;
    if(actId){
      var d=api.detail||{};
      var dvars=Object.assign({},live,{
        TITLE:d.title||"",
        TITLE_2:d.title_2||"",
        CREAT_TIME:(d.creat_time||"").slice(0,10),
        UPDAT_TIME:(d.updat_time||"").slice(0,10),
        ACTIVITY_PIC:d.pic||"",
        DESCRIPTION:(d.description||"").replace(/\n/g,"<br>"),
        BACK_URL:"index.html?activity"
      });
      bodyHtml=fill(tpl,dvars);
    }else{
      var items=mapAct(api.list);
      var pageNav="";
      if(api.total_pages>1){
        for(var i=1;i<=api.total_pages;i++){
          pageNav+=i===api.page
            ?'<span style="padding:6px 14px;background:var(--primary);color:#fff;border-radius:8px;display:inline-block;min-width:36px;text-align:center;font-size:14px;">'+i+'</span>'
            :'<a href="index.html?activity&page='+i+'" style="padding:6px 14px;border:1px solid var(--border);border-radius:8px;text-decoration:none;color:var(--primary);background:var(--surface);display:inline-block;min-width:36px;text-align:center;font-size:14px;">'+i+'</a>';
        }
      }
      var lvars=Object.assign({},live,{
        EMPTY_MSG:items.length?"":"<p style='text-align:center;color:var(--text-secondary);padding:24px;'>目前沒有最新活動。</p>",
        PAGE_NAV:pageNav
      });
      bodyHtml=fill(expand(tpl,"ROW_ACTIVITY",items),lvars);
    }
    document.getElementById("slot-body").innerHTML=bodyHtml;
    Array.prototype.forEach.call(document.querySelectorAll(".reveal"),function(el,i){
      setTimeout(function(){el.classList.add("visible");},60*i);
    });
  }).catch(function(err){
    document.getElementById("slot-body").innerHTML='<p style="padding:24px;color:red;">'+err.message+'</p>';
  });

}else if(isAlbum){
// ALBUM MODE  index.html?album  或  index.html?album&id=X
  var alId=params.get("id");
  var alPage=params.get("page")||"1";
  var tplUrl=alId?"skin/body_album_detail.html":"skin/body_album_list.html";
  var apiUrl=alId
    ?"skin/body_album_list.php?id="+encodeURIComponent(alId)
    :"skin/body_album_list.php?page="+encodeURIComponent(alPage);
  Promise.all([
    fetch("skin/top.html").then(function(r){if(!r.ok)throw new Error("top 載入失敗");return r.text();}),
    fetch("skin/footer.html").then(function(r){if(!r.ok)throw new Error("footer 載入失敗");return r.text();}),
    fetch(tplUrl).then(function(r){if(!r.ok)throw new Error(tplUrl+" 載入失敗");return r.text();}),
    loadNews(apiUrl)
  ]).then(function(res){
    var top=res[0],foot=res[1],tpl=res[2],api=res[3];
    var live=renderChrome(top,foot,api);
    var bodyHtml;
    if(alId){
      var d=api.detail||{};
      var photos=d.photos||[];
      var dvars=Object.assign({},live,{
        TITLE:d.title||"",
        PHOTO_COUNT:String(photos.length),
        ALBUM_PIC:d.cover||"",
        BACK_URL:"index.html?album"
      });
      bodyHtml=fill(tpl,dvars);
    }else{
      var items=mapAlbum(api.list);
      var pageNav="";
      if(api.total_pages>1){
        for(var i=1;i<=api.total_pages;i++){
          pageNav+=i===api.page
            ?'<span style="padding:6px 14px;background:var(--primary);color:#fff;border-radius:8px;display:inline-block;min-width:36px;text-align:center;font-size:14px;">'+i+'</span>'
            :'<a href="index.html?album&page='+i+'" style="padding:6px 14px;border:1px solid var(--border);border-radius:8px;text-decoration:none;color:var(--primary);background:var(--surface);display:inline-block;min-width:36px;text-align:center;font-size:14px;">'+i+'</a>';
        }
      }
      var lvars=Object.assign({},live,{
        EMPTY_MSG:items.length?"":"<p style='text-align:center;color:var(--text-secondary);padding:24px;'>目前沒有相簿。</p>",
        PAGE_NAV:pageNav
      });
      bodyHtml=fill(expand(tpl,"ROW_ALBUM",items),lvars);
    }
    document.getElementById("slot-body").innerHTML=bodyHtml;
    if(alId){initAlbumPhotos((api.detail||{}).photos||[]);}
    Array.prototype.forEach.call(document.querySelectorAll(".reveal"),function(el,i){
      setTimeout(function(){el.classList.add("visible");},60*i);
    });
  }).catch(function(err){
    document.getElementById("slot-body").innerHTML='<p style="padding:24px;color:red;">'+err.message+'</p>';
  });

}else if(isProducts){
// PRODUCTS MODE  index.html?products  或  index.html?products&id=X
  var prodId=params.get("id");
  var prodPage=params.get("page")||"1";
  var prodTplUrl=prodId?"skin/body_products_detail.html":"skin/body_products_list.html";
  var prodApiUrl=prodId
    ?"skin/body_products_list.php?id="+encodeURIComponent(prodId)
    :"skin/body_products_list.php?page="+encodeURIComponent(prodPage);
  Promise.all([
    fetch("skin/top.html").then(function(r){if(!r.ok)throw new Error("top 載入失敗");return r.text();}),
    fetch("skin/footer.html").then(function(r){if(!r.ok)throw new Error("footer 載入失敗");return r.text();}),
    fetch(prodTplUrl).then(function(r){if(!r.ok)throw new Error(prodTplUrl+" 載入失敗");return r.text();}),
    loadNews(prodApiUrl)
  ]).then(function(res){
    var top=res[0],foot=res[1],tpl=res[2],api=res[3];
    var live=renderChrome(top,foot,api);
    var bodyHtml;
    if(prodId){
      var d=api.detail||{};
      var photos=d.photos||[];
      var photoRows=photos.map(function(src,idx){return {PHOTO_SRC:src,PHOTO_IDX:String(idx)};});
      var prevNextNav="";
      if(d.prev&&d.prev.detail_url){
        prevNextNav+='<a href="'+d.prev.detail_url+'" style="padding:8px 20px;border:1px solid var(--border);border-radius:8px;text-decoration:none;color:var(--primary);background:var(--surface);display:inline-block;font-size:14px;">← 上一筆</a>';
      }else{
        prevNextNav+='<span></span>';
      }
      if(d.next&&d.next.detail_url){
        prevNextNav+='<a href="'+d.next.detail_url+'" style="padding:8px 20px;border:1px solid var(--border);border-radius:8px;text-decoration:none;color:var(--primary);background:var(--surface);display:inline-block;font-size:14px;">下一筆 →</a>';
      }else{
        prevNextNav+='<span></span>';
      }
      var dvars=Object.assign({},live,{
        TITLE:d.title||"",
        TITLE_2:d.title_2||"",
        DESCRIPTION:(d.description||"").replace(/\n/g,"<br>"),
        PRODUCT_PIC:d.pic||"",
        PRODUCT_ID:String(d.id||""),
        PRODUCT_PRICE:String(d.price||0),
        PRODUCT_NUM:String(d.product_num||0),
        PRODUCT_RENUM:String(d.product_renum||0),
        ADD_CART_URL:"#",
        BACK_URL:"index.html?products",
        PREV_NEXT_NAV:prevNextNav
      });
      bodyHtml=fill(expand(tpl,"ROW_PHOTO",photoRows),dvars);
    }else{
      var items=mapProdList(api.list);
      var pageNav="";
      if(api.total_pages>1){
        for(var i=1;i<=api.total_pages;i++){
          pageNav+=i===api.page
            ?'<span style="padding:6px 14px;background:var(--primary);color:#fff;border-radius:8px;display:inline-block;min-width:36px;text-align:center;font-size:14px;">'+i+'</span>'
            :'<a href="index.html?products&page='+i+'" style="padding:6px 14px;border:1px solid var(--border);border-radius:8px;text-decoration:none;color:var(--primary);background:var(--surface);display:inline-block;min-width:36px;text-align:center;font-size:14px;">'+i+'</a>';
        }
      }
      var lvars=Object.assign({},live,{
        EMPTY_MSG:items.length?"":"<p style='text-align:center;color:var(--text-secondary);padding:24px;'>目前沒有產品。</p>",
        PAGE_NAV:pageNav
      });
      bodyHtml=fill(expand(tpl,"ROW_PRODUCT",items),lvars);
    }
    document.getElementById("slot-body").innerHTML=bodyHtml;
    if(prodId){initProductPhotos((api.detail||{}).photos||[]);initProductDetailCart(api);}
    else{initProductListCart(api);}
    Array.prototype.forEach.call(document.querySelectorAll(".reveal"),function(el,i){
      setTimeout(function(){el.classList.add("visible");},60*i);
    });
  }).catch(function(err){
    document.getElementById("slot-body").innerHTML='<p style="padding:24px;color:red;">'+err.message+'</p>';
  });

}else if(isMember){
// MEMBER MODE  index.html?member  或  index.html?member&edit  或  index.html?member&activate=CODE
  var activateCode=params.get("activate");
  var isEdit=params.get("edit")!==null;
  if(activateCode){
    Promise.all([
      fetch("skin/top.html").then(function(r){if(!r.ok)throw new Error("top 載入失敗");return r.text();}),
      fetch("skin/footer.html").then(function(r){if(!r.ok)throw new Error("footer 載入失敗");return r.text();})
    ]).then(function(res){
      var top=res[0],foot=res[1];
      return fetch("skin/body_member_list.php?activate="+encodeURIComponent(activateCode))
        .then(function(r){return r.json();})
        .then(function(j){
          renderChrome(top,foot,j);
          var html;
          if(j.success){
            html='<section class="about-section" style="text-align:center;padding:60px 24px;">'
              +'<div style="font-size:48px;margin-bottom:16px;">✅</div>'
              +'<h2>帳號已啟用</h2>'
              +'<p style="color:var(--text-secondary);margin:12px 0 24px;">歡迎 <strong>'+(j.member_name||'')+'</strong>，現在可以正常使用了。</p>'
              +'<a class="button button-primary" href="index.html?member" style="display:inline-block;">進入會員專區</a>'
              +'</section>';
          }else{
            html='<section class="about-section" style="text-align:center;padding:60px 24px;">'
              +'<div style="font-size:48px;margin-bottom:16px;">⚠️</div>'
              +'<h2>啟用失敗</h2>'
              +'<p style="color:var(--text-secondary);margin:12px 0 24px;">'+(j.error||'無效或過期的啟用連結。')+'</p>'
              +'<a class="button button-primary" href="index.html?member" style="display:inline-block;">回到會員頁</a>'
              +'</section>';
          }
          document.getElementById("slot-body").innerHTML=html;
        })
        .catch(function(err){
          document.getElementById("slot-body").innerHTML='<p style="padding:24px;color:red;">'+err.message+'</p>';
        });
    }).catch(function(err){
      document.getElementById("slot-body").innerHTML='<p style="padding:24px;color:red;">'+err.message+'</p>';
    });
  } else {
  var tplUrl=isEdit?"skin/body_member_edit.html":"skin/body_member_list.html";
  var apiUrl=isEdit?"skin/body_member_list.php?edit":"skin/body_member_list.php";
  Promise.all([
    fetch("skin/top.html").then(function(r){if(!r.ok)throw new Error("top 載入失敗");return r.text();}),
    fetch("skin/footer.html").then(function(r){if(!r.ok)throw new Error("footer 載入失敗");return r.text();}),
    fetch(tplUrl).then(function(r){if(!r.ok)throw new Error(tplUrl+" 載入失敗");return r.text();}),
    loadNews(apiUrl)
  ]).then(function(res){
    var top=res[0],foot=res[1],tpl=res[2],api=res[3];
    var live=renderChrome(top,foot,api);
    var bodyHtml;
    if(isEdit){
      var m=api.member||{};
      var dvars=Object.assign({},live,{
        MSG:api.error||"",
        FORM_ACTION:"skin/body_member_list.php?action=edit",
        CSRF_TOKEN:api.csrf_token||"",
        MEMBER_NAME:m.member_name||"",
        MEMBER_EMAIL:m.member_email||"",
        MEMBER_PHONE:m.member_phone||"",
        MEMBER_ADDRESS:m.member_address||""
      });
      bodyHtml=fill(tpl,dvars);
      document.getElementById("slot-body").innerHTML=bodyHtml;
      initMemberEdit(api);
    }else{
      var items=(api.orders||[]).map(function(o){
        return {
          ORDER_CODE:o.cart_code||"",
          ORDER_TIME:(o.creat_time||"").slice(0,10),
          ORDER_COUNT:String(o.item_count||0),
          ORDER_STATUS:o.status_text||"已完成"
        };
      });
      var pageNav="";
      if((api.total_pages||1)>1){
        for(var i=1;i<=(api.total_pages||1);i++){
          pageNav+=i===(api.orders_page||1)
            ?'<span style="padding:6px 14px;background:var(--primary);color:#fff;border-radius:8px;display:inline-block;min-width:36px;text-align:center;font-size:14px;">'+i+'</span>'
            :'<a href="index.html?member&page='+i+'" style="padding:6px 14px;border:1px solid var(--border);border-radius:8px;text-decoration:none;color:var(--primary);background:var(--surface);display:inline-block;min-width:36px;text-align:center;font-size:14px;">'+i+'</a>';
        }
      }
      var lvars=Object.assign({},live,{
        LOGIN_STYLE:api.logged_in?"display:none;":"display:block;",
        DASH_STYLE:api.logged_in?"display:block;":"display:none;",
        MEMBER_NAME:api.member_name||"",
        CSRF_TOKEN:api.csrf_token||"",
        MEMBER_EDIT_URL:"index.html?member&edit",
        ORDER_PAGER:pageNav
      });
      bodyHtml=fill(expand(tpl,"ROW_ORDER",items),lvars);
      document.getElementById("slot-body").innerHTML=bodyHtml;
      var oe=document.getElementById("orderEmpty");
      if(oe&&items.length)oe.style.display="none";
      initMemberPage(api);
    }
  }).catch(function(err){
    document.getElementById("slot-body").innerHTML='<p style="padding:24px;color:red;">'+err.message+'</p>';
  });
  }
}else{
  Promise.all([
    "skin/top.html","skin/body_index_news.html","skin/body_index_activity.html",
    "skin/body_index_album.html","skin/body_index_products.html",
    "skin/body_index_aboutus.html","skin/footer.html"
  ].map(function(u){
    return fetch(u).then(function(r){if(!r.ok)throw new Error(u+" 載入失敗 "+r.status);return r.text();});
  })).then(function(p){
    return loadSite().then(function(api){return {p:p,api:api};});
  }).then(function(all){
    var p=all.p,api=all.api;
    var live=renderChrome(p[0],p[6],api);
    var nItems=mapNews((api.home&&api.home.news)||[]);
    var aItems=mapAct((api.home&&api.home.activity)||[]);
    var pItems=mapAlbum((api.home&&api.home.album)||[]);
    var gItems=mapProd((api.home&&api.home.products)||[]);
    var newsEmpty=nItems.length?"":"<p style=\"text-align:center;color:var(--text-secondary);padding:24px 0 0;font-size:14px;\">目前沒有最新消息。</p>";
    var nsec=fill(expand(p[1],"ROW_NEWS",nItems),Object.assign({},live,{EMPTY_MSG:newsEmpty}));
    var asec=fill(expand(p[2],"ROW_ACTIVITY",aItems),live);
    var psec=fill(expand(p[3],"ROW_ALBUM",pItems),live);
    var gsec=fill(expand(p[4],"ROW_PRODUCT",gItems),live);
    var aboutHtml=p[5]||"";
    var aboutVars=Object.assign({},live,{
      ABOUT_CONTENT:(api&&api.about_content)||"歡迎來到 "+live.WEB_TITLE+"，關於我們內容請至後台「關於我們管理」填寫。",
      EMPTY_MSG:(api&&api.about_content)?"":""
    });
    var DIVIDER='<div class="home-divider"></div>';
    var sections=[nsec,asec,psec,gsec].filter(Boolean);
    var body=sections.join(DIVIDER);
    if(aboutHtml)body+=DIVIDER+fill(aboutHtml,aboutVars);
    document.getElementById("slot-body").innerHTML=body;
    if(api&&api.blocks){
      var secMap={homeNews:"news",homeActivity:"activity",homeAlbum:"album",homeProducts:"product"};
      Object.keys(secMap).forEach(function(id){
        var on=(api.blocks[secMap[id]]===true);
        var navLink=document.querySelector('#topNav a[data-block="'+secMap[id]+'"]');
        if(navLink&&!on)navLink.style.display="none";
        var sec=document.getElementById(id);
        if(sec&&!on)sec.style.display="none";
      });
      var mOn=(api.blocks.member===true);
      var mLink=document.querySelector('#topNav a[data-block="member"]');
      if(mLink&&!mOn)mLink.style.display="none";
      var av=document.getElementById("avatarIcon");
      if(av&&!mOn)av.style.display="none";
      Array.prototype.forEach.call(document.querySelectorAll("#slot-body .home-section"),function(sec){
        if(sec.style.display==="none"&&sec.previousElementSibling&&sec.previousElementSibling.classList.contains("home-divider"))
          sec.previousElementSibling.style.display="none";
      });
      Array.prototype.forEach.call(document.querySelectorAll("#slot-body .home-divider"),function(d,i,all){
        var next=d.nextElementSibling;
        if(!next||(next.classList.contains("home-divider"))||next.style.display==="none")d.style.display="none";
      });
      var firstVis=null;
      Array.prototype.forEach.call(document.querySelectorAll("#slot-body > *"),function(el){if(!firstVis&&el.style.display!=="none")firstVis=el;});
      if(firstVis&&firstVis.classList.contains("home-divider"))firstVis.style.display="none";
    }
    Array.prototype.forEach.call(document.querySelectorAll(".reveal"),function(el,i){
      setTimeout(function(){el.classList.add("visible");},60*i);
    });
    (function(){
      var track=document.getElementById('actTrack');
      if(!track)return;
      var cards=track.querySelectorAll('.act-card');
      if(cards.length<=4){
        document.getElementById('actPrev').style.display='none';
        document.getElementById('actNext').style.display='none';
        return;
      }
      var prev=document.getElementById('actPrev');
      var next=document.getElementById('actNext');
      function atStart(){return track.scrollLeft<=2}
      function atEnd(){return track.scrollLeft>=track.scrollWidth-track.clientWidth-2}
      function update(){
        prev.classList.toggle('disabled',atStart());
        next.classList.toggle('disabled',atEnd());
      }
      function scrollPage(dir){track.scrollBy({left:dir*track.clientWidth,behavior:'smooth'})}
      prev.addEventListener('click',function(){if(!atStart())scrollPage(-1)});
      next.addEventListener('click',function(){if(!atEnd())scrollPage(1)});
      track.addEventListener('scroll',function(){requestAnimationFrame(function(){update()})});
      update();
    })();
  }).catch(function(err){
    document.getElementById("slot-body").innerHTML='<p style="padding:24px;color:red;">'+err.message+'<br>請用 http://localhost/alan_php_mysql/index.html 開啟（不要用檔案總管雙擊，fetch 載入 skin 需經 HTTP）。</p>';
  });
}

// ── 產品加入購物車（session 暫存：訂單編號 + product_id + qty）─────────
function postAddCart(api, pid, qty, okMsg){
  var fd=new FormData();
  fd.append("action","add");
  fd.append("csrf_token",api.csrf_token||"");
  fd.append("product_id",pid);
  fd.append("qty",qty);
  return fetch("skin/body_shopping_cart.php",{method:"POST",body:fd})
    .then(function(r){return r.json();})
    .then(function(j){
      if(!j.success)throw new Error(j.error||"加入購物車失敗。");
      var badge=document.getElementById("cartBadge");
      if(badge&&j.cart_count!==undefined)badge.textContent=String(j.cart_count);
      if(okMsg)okMsg(j.message||"已加入購物車。");
      return j;
    });
}
function initProductListCart(api){
  Array.prototype.forEach.call(document.querySelectorAll(".add-cart-btn"),function(btn){
    btn.addEventListener("click",function(e){
      e.preventDefault();
      var old=btn.textContent;
      btn.textContent="加入中…";
      postAddCart(api,btn.getAttribute("data-pid"),1,function(msg){
        btn.textContent=old;
        alert(msg);
      }).catch(function(err){
        btn.textContent=old;
        alert(err.message);
      });
    });
  });
}
function initProductDetailCart(api){
  var form=document.querySelector("#productDetail form");
  if(!form)return;
  form.addEventListener("submit",function(e){
    e.preventDefault();
    var pid=form.querySelector('input[name="product_id"]');
    var qty=form.querySelector('input[name="qty"]');
    var btn=form.querySelector('button[type="submit"]');
    var old=btn?btn.textContent:"";
    if(btn)btn.textContent="加入中…";
    postAddCart(api,pid?pid.value:"",qty?qty.value:"1",function(msg){
      if(btn)btn.textContent=old;
      alert(msg);
    }).catch(function(err){
      if(btn)btn.textContent=old;
      alert(err.message);
    });
  });
}

// ── 產品 sub 縮圖 + Lightbox（同相簿內頁效果）────────────────────────────
function initProductPhotos(photos){
  var total=photos.length;
  var _lbIdx=0;

  function openLightbox(idx){
    _lbIdx=idx;
    var lb=document.getElementById('lightbox');
    var img=document.getElementById('lbImg');
    if(!lb||!img)return;
    img.src=photos[idx];
    img.style.opacity='0';
    lb.style.display='flex';
    document.body.style.overflow='hidden';
    setTimeout(function(){img.style.opacity='1';},60);
    updateCounter();
  }

  function closeLightbox(){
    var lb=document.getElementById('lightbox');
    var img=document.getElementById('lbImg');
    if(!lb)return;
    if(img)img.style.opacity='0';
    setTimeout(function(){lb.style.display='none';document.body.style.overflow='';},300);
  }

  function lightNav(dir){
    _lbIdx=(_lbIdx+dir+total)%total;
    var img=document.getElementById('lbImg');
    if(!img)return;
    img.style.opacity='0';
    setTimeout(function(){
      img.src=photos[_lbIdx];
      img.onload=function(){img.style.opacity='1';};
      updateCounter();
    },200);
  }

  function updateCounter(){
    var c=document.getElementById('lbCounter');
    if(c)c.textContent=(_lbIdx+1)+' / '+total;
  }

  // ── wire up 縮圖點擊 ──
  var row=document.getElementById('productPhotoRow');
  if(row){
    Array.prototype.forEach.call(row.querySelectorAll('img'),function(el){
      el.addEventListener('click',function(){openLightbox(parseInt(this.dataset.idx||'0',10));});
    });
    if(total===0){row.innerHTML='<p style="text-align:center;color:var(--text-secondary);padding:12px;">此產品暫無附加圖片。</p>';}
  }

  // ── wire up lightbox buttons ──
  var lbBox=document.getElementById('lightbox');
  var lbClose=document.getElementById('lbCloseBtn');
  var lbPrev=document.getElementById('lbPrevBtn');
  var lbNext=document.getElementById('lbNextBtn');
  if(lbClose)lbClose.addEventListener('click',closeLightbox);
  if(lbPrev)lbPrev.addEventListener('click',function(){lightNav(-1);});
  if(lbNext)lbNext.addEventListener('click',function(){lightNav(1);});
  if(lbBox)lbBox.addEventListener('click',function(e){if(e.target===this)closeLightbox();});

  // hover on lightbox buttons
  [lbClose,lbPrev,lbNext].forEach(function(btn){
    if(!btn)return;
    btn.addEventListener('mouseenter',function(){btn.style.background='rgba(255,255,255,0.28)';});
    btn.addEventListener('mouseleave',function(){btn.style.background='rgba(255,255,255,0.1)';});
  });

  // keyboard: ESC / ← / →
  document.addEventListener('keydown',function(e){
    var lb=document.getElementById('lightbox');
    if(!lb||lb.style.display==='none')return;
    if(e.key==='Escape')closeLightbox();
    if(e.key==='ArrowLeft')lightNav(-1);
    if(e.key==='ArrowRight')lightNav(1);
  });
}

// ── 相簿照片分頁 + Lightbox ─────────────────────────────────────────────────
function initAlbumPhotos(photos){
  var PER=6,_page=0,_lbIdx=0;
  var total=photos.length;
  var totalPages=Math.ceil(total/PER);

  function renderPage(){
    var grid=document.getElementById('photoGrid');
    if(!grid)return;
    var start=_page*PER;
    var slice=photos.slice(start,start+PER);
    grid.innerHTML=slice.map(function(u,i){
      var idx=start+i;
      return '<img src="'+u+'" loading="lazy" alt="" style="cursor:pointer" data-idx="'+idx+'">';
    }).join('');
    grid.querySelectorAll('img').forEach(function(el){
      el.addEventListener('click',function(){openLightbox(parseInt(this.dataset.idx));});
    });
    var ind=document.getElementById('photoPageInd');
    if(ind)ind.textContent=(_page+1)+'/'+totalPages;
    var pPrev=document.getElementById('photoPrevBtn');
    var pNext=document.getElementById('photoNextBtn');
    if(pPrev)pPrev.style.opacity=_page===0?'0.35':'1';
    if(pNext)pNext.style.opacity=_page===totalPages-1?'0.35':'1';
    // fade-in effect
    grid.style.opacity='0';
    requestAnimationFrame(function(){grid.style.opacity='1';});
  }

  function navPage(dir){
    if(totalPages<=1)return;
    _page=(_page+dir+totalPages)%totalPages;
    renderPage();
  }

  function openLightbox(idx){
    _lbIdx=idx;
    var lb=document.getElementById('lightbox');
    var img=document.getElementById('lbImg');
    if(!lb||!img)return;
    img.src=photos[idx];
    img.style.opacity='0';
    lb.style.display='flex';
    document.body.style.overflow='hidden';
    setTimeout(function(){img.style.opacity='1';},60);
    updateCounter();
  }

  function closeLightbox(){
    var lb=document.getElementById('lightbox');
    var img=document.getElementById('lbImg');
    if(!lb)return;
    if(img)img.style.opacity='0';
    setTimeout(function(){lb.style.display='none';document.body.style.overflow='';},300);
  }

  function lightNav(dir){
    _lbIdx=(_lbIdx+dir+total)%total;
    var img=document.getElementById('lbImg');
    if(!img)return;
    img.style.opacity='0';
    setTimeout(function(){
      img.src=photos[_lbIdx];
      img.onload=function(){img.style.opacity='1';};
      updateCounter();
    },200);
  }

  function updateCounter(){
    var c=document.getElementById('lbCounter');
    if(c)c.textContent=(_lbIdx+1)+' / '+total;
  }

  // ── wire up pagination buttons ──
  var pPrev=document.getElementById('photoPrevBtn');
  var pNext=document.getElementById('photoNextBtn');
  if(pPrev)pPrev.addEventListener('click',function(){navPage(-1);});
  if(pNext)pNext.addEventListener('click',function(){navPage(1);});

  // hover effect on pagination
  [pPrev,pNext].forEach(function(btn){
    if(!btn)return;
    btn.addEventListener('mouseenter',function(){btn.style.background='var(--primary)';btn.style.color='#fff';btn.style.borderColor='var(--primary)';});
    btn.addEventListener('mouseleave',function(){btn.style.background='var(--surface)';btn.style.color='var(--primary)';btn.style.borderColor='var(--border)';});
  });

  // ── wire up lightbox buttons ──
  var lbBox=document.getElementById('lightbox');
  var lbClose=document.getElementById('lbCloseBtn');
  var lbPrev=document.getElementById('lbPrevBtn');
  var lbNext=document.getElementById('lbNextBtn');
  if(lbClose)lbClose.addEventListener('click',closeLightbox);
  if(lbPrev)lbPrev.addEventListener('click',function(){lightNav(-1);});
  if(lbNext)lbNext.addEventListener('click',function(){lightNav(1);});
  if(lbBox)lbBox.addEventListener('click',function(e){if(e.target===this)closeLightbox();});

  // hover on lightbox buttons
  [lbClose,lbPrev,lbNext].forEach(function(btn){
    if(!btn)return;
    btn.addEventListener('mouseenter',function(){btn.style.background='rgba(255,255,255,0.28)';});
    btn.addEventListener('mouseleave',function(){btn.style.background='rgba(255,255,255,0.1)';});
  });

  // keyboard: ESC / ← / →
  document.addEventListener('keydown',function(e){
    var lb=document.getElementById('lightbox');
    if(!lb||lb.style.display==='none')return;
    if(e.key==='Escape')closeLightbox();
    if(e.key==='ArrowLeft')lightNav(-1);
    if(e.key==='ArrowRight')lightNav(1);
  });

  // initial render
  if(total===0){
    var grid=document.getElementById('photoGrid');
    if(grid)grid.innerHTML='<p style="text-align:center;color:var(--text-secondary);padding:24px;">此相簿暫無照片。</p>';
  }else{
    renderPage();
  }
}

// ── 會員頁互動 ─────────────────────────────────────────────────────────
function initMemberPage(api){
  var csrf = api.csrf_token || "";

  function postForm(url, form, msgEl, onOk){
    var fd = new URLSearchParams(new FormData(form));
    fetch(url, { method: "POST", body: fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if(j.success){
          if(msgEl){ msgEl.style.color="var(--primary)"; msgEl.textContent=j.message||"成功。"; }
          if(onOk) onOk(j);
        }else{
          if(msgEl){ msgEl.style.color="red"; msgEl.textContent=j.error||"發生錯誤。"; }
        }
      })
      .catch(function(err){ if(msgEl){ msgEl.style.color="red"; msgEl.textContent="連線失敗："+err.message; } });
  }

  // ── 未登入：登入表單（含前端格式預檢，規則同後端）──
  var loginForm = document.getElementById("memberLoginForm");
  if(loginForm){
    loginForm.addEventListener("submit", function(e){
      e.preventDefault();
      var msgEl = document.getElementById("loginMsg");
      var uid = (loginForm.user_id ? loginForm.user_id.value : "").trim();
      var pwd = loginForm.user_pwd ? loginForm.user_pwd.value : "";
      if(!/^[A-Za-z0-9._-]{4,20}$/.test(uid)){
        if(msgEl){ msgEl.style.color="red"; msgEl.textContent="帳號格式不正確（4-20 字元，僅英數及 ._ -）。"; }
        return;
      }
      if(pwd.length < 6){
        if(msgEl){ msgEl.style.color="red"; msgEl.textContent="密碼至少需要 6 個字元。"; }
        return;
      }
      postForm("skin/body_member_list.php?action=login", loginForm, msgEl, function(j){
        location.href = "index.html?member";
      });
    });
  }

  // ── 未登入：註冊表單 ──
  var regForm = document.getElementById("memberRegisterForm");
  if(regForm){
    regForm.addEventListener("submit", function(e){
      e.preventDefault();
      postForm("skin/body_member_list.php?action=register", regForm, document.getElementById("regMsg"), function(j){
        regForm.reset();
        setTimeout(function(){ location.href = "index.html?member"; }, 800);
      });
    });
  }

  // ── 忘記密碼 toggle ──
  var forgotToggle = document.getElementById("forgotToggle");
  var forgotPanel  = document.getElementById("forgotPanel");
  if(forgotToggle && forgotPanel){
    forgotToggle.addEventListener("click", function(){
      forgotPanel.style.display = forgotPanel.style.display === "none" ? "block" : "none";
    });
  }
  var forgotCancel = document.getElementById("forgotCancel");
  if(forgotCancel){
    forgotCancel.addEventListener("click", function(){
      if(forgotPanel) forgotPanel.style.display = "none";
      var fm = document.getElementById("forgotMsg");
      if(fm) fm.textContent = "";
    });
  }
  // 忘記密碼送出
  var forgotForm = document.getElementById("memberForgotForm");
  if(forgotForm){
    forgotForm.addEventListener("submit", function(e){
      e.preventDefault();
      postForm("skin/body_member_list.php?action=forgot", forgotForm, document.getElementById("forgotMsg"));
    });
  }

  // ── 已登入：登出（由 <a> 連結直接跳轉，無需 JS 處理）──
}

// ── 會員編輯頁互動 ─────────────────────────────────────────────────────
function initMemberEdit(api){
  var csrf = api.csrf_token || "";
  var form = document.getElementById("memberEditForm");
  if(!form) return;
  var msgEl = document.getElementById("editMsg");

  form.addEventListener("submit", function(e){
    e.preventDefault();
    var fd = new URLSearchParams(new FormData(form));
    fetch("skin/body_member_list.php?action=edit", { method: "POST", body: fd })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if(msgEl){
          if(j.success){
            msgEl.style.color = "var(--primary)";
            msgEl.textContent = j.message || "資料已更新。";
          }else{
            msgEl.style.color = "red";
            msgEl.textContent = j.error || "更新失敗。";
          }
        }
      })
      .catch(function(err){
        if(msgEl){ msgEl.style.color = "red"; msgEl.textContent = "連線失敗：" + err.message; }
      });
  });
}


