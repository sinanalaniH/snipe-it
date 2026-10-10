{{-- Theme-mode FOUT preflight.

     Runs synchronously in <head> BEFORE the stylesheet loads so users
     whose stored preference is dark do not see a
     flash of light content on first paint.

     Included from both layouts/default.blade.php and layouts/basic.blade.php
     so login and error pages get the same immediate theme application. --}}
<script nonce="{{ csrf_token() }}">
    (function () {
        try {
            var stored = localStorage.getItem('theme');
            var theme = stored || 'light';
            document.documentElement.setAttribute('data-theme', theme);
        } catch (e) {
            // localStorage unavailable (private-mode edge
            // cases). Fall back to the data-theme attribute already on
            // the <html> tag.
        }
    })();
</script>
