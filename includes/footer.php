        </main>
    </div>
</div>
<script>
document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
        if (!confirm(form.dataset.confirm)) ev.preventDefault();
    });
});
</script>
</body>
</html>
