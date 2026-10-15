/**
 * @file
 * Live character counters on item fields.
 *
 * A field with data-ishop-counter="60,70,80" shows "n / 80"; past 60 and 70
 * it warns which platforms will cut the text.
 */
((Drupal, once) => {
  const names = { 60: 'Grailed', 70: 'Vinted', 1000: 'most platforms' };
  Drupal.behaviors.ishopCounters = {
    attach(context) {
      once('ishop-counter', '[data-ishop-counter]', context).forEach((field) => {
        const limits = field.dataset.ishopCounter.split(',').map(Number);
        const max = limits[limits.length - 1];
        const out = document.createElement('div');
        out.className = 'ishop-counter';
        out.setAttribute('aria-live', 'polite');
        field.insertAdjacentElement('afterend', out);
        const update = () => {
          const n = [...field.value].length;
          const over = limits.filter((l) => n > l);
          let text = `${n} / ${max}`;
          if (over.length) {
            const cut = over.map((l) => names[l] || `${l}`).join(', ');
            text += ` · too long for ${cut}`;
          }
          out.textContent = text;
          out.classList.toggle('ishop-counter--warn', over.length > 0);
        };
        field.addEventListener('input', update);
        update();
      });
    },
  };
})(Drupal, once);
