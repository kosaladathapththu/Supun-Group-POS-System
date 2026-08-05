const simpleStyle=document.createElement('link');simpleStyle.rel='stylesheet';simpleStyle.href='assets/simple.css';document.head.appendChild(simpleStyle);
document.querySelector('[data-menu]')?.addEventListener('click',()=>document.querySelector('.sidebar')?.classList.toggle('open'));
document.querySelectorAll('[data-confirm]').forEach(el=>el.addEventListener('click',e=>{if(!confirm(el.dataset.confirm))e.preventDefault()}));
