import { chromium } from 'playwright-core';
import fs from 'fs';
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium-1194/chrome-linux/chrome',args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu']});
const files=fs.readdirSync('.').filter(f=>/^p\d\d.*\.html$/.test(f)).sort();
const POS=new Set(['p01-pos.html','p02-payment.html','p03-register.html']);
for(const f of files){
  const out=f.replace('.html','.png');
  const p=await b.newPage({viewport:{width:1500,height:940},deviceScaleFactor:2});
  p.on('pageerror',e=>console.log('ERR',f,e.message));
  await p.goto('file://'+process.cwd()+'/'+f);
  await p.waitForTimeout(400);
  if(POS.has(f)){
    await p.screenshot({path:out});
    console.log('shot',out,'(fixed 940)');
  } else {
    // measure the inner scrolling pane, grow the viewport to fit it
    const need=await p.evaluate(()=>{
      const bd=document.querySelector('.body');
      const tb=document.querySelector('.tb');
      const sb=document.querySelector('.sb');
      const inner=bd?bd.scrollHeight:0;
      const top=tb?tb.getBoundingClientRect().height:0;
      const nav=sb?sb.scrollHeight:0;
      return {content:Math.ceil(inner+top)+8, nav:Math.ceil(nav)+8};
    });
    const h=Math.round(Math.min(Math.max(need.content, Math.min(need.nav, need.content*1.12), 900), 4200));
    await p.setViewportSize({width:1500,height:h});
    await p.waitForTimeout(300);
    await p.screenshot({path:out});
    console.log('shot',out,'h='+h);
  }
  await p.close();
}
process.exit(0);
