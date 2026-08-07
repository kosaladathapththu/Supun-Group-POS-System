document.addEventListener('DOMContentLoaded',()=>{
  if(!location.pathname.endsWith('/bulk_import.php'))return;
  const form=document.querySelector('.confirm-options'),old=form?.querySelector('input[name="update_prices"]')?.closest('label');
  if(!form||!old)return;
  const box=document.createElement('fieldset');box.className='import-price-strategy';
  box.innerHTML='<legend>When the same item code already exists, what should happen?</legend><label class="selected"><input type="radio" name="price_strategy" value="merge_keep" checked><span><b>Add to the same product</b><small>Increase stock and calculate weighted-average buying cost. Keep current selling prices.</small><em>Recommended</em></span></label><label><input type="radio" name="price_strategy" value="merge_update"><span><b>Add to the same product and use new selling prices</b><small>Increase stock, calculate weighted-average buying cost, and replace retail and wholesale prices with the Excel prices.</small></span></label><label><input type="radio" name="price_strategy" value="separate_batch"><span><b>Create a separate new-price batch</b><small>Keep old stock unchanged and create a separate product batch. The old batch disappears from sales when its stock reaches zero.</small></span></label>';
  old.replaceWith(box);box.addEventListener('change',()=>box.querySelectorAll('label').forEach(label=>label.classList.toggle('selected',label.querySelector('input').checked)));
});
