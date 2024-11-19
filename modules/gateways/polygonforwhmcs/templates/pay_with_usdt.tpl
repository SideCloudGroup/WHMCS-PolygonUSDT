<div class="pay">
    <button id="pay" class="btn btn-primary">
        Pay with USDT
    </button>
</div>

<script>
    $('#pay').on('click', () => {
        fetch(window.location.href + '&act=create')
            .then((r) => r.json())
            .then((r) => {
                if (r.status) {
                    window.location.reload(true)
                } else {
                    alert(r.error)
                }
            })
    })
</script>

<style>
    .pay img {
        height: 25px;
    }
</style>
