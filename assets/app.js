document.querySelector('[data-menu]')?.addEventListener('click',()=>document.querySelector('.sidebar')?.classList.toggle('open'));
document.querySelectorAll('[data-confirm]').forEach(el=>el.addEventListener('click',e=>{if(!confirm(el.dataset.confirm))e.preventDefault()}));

