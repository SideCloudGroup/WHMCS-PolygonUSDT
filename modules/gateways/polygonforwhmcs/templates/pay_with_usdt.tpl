<script src="https://unpkg.com/htmx.org@2.0.3"></script>

<div class="pay">
    <button id="pay" class="btn btn-primary"
            hx-get="&act=create"
            hx-trigger="click"
            hx-swap="none"
            hx-on="htmx:afterRequest: handleResponse">
        Pay with USDT
    </button>
</div>

<script>
    function handleResponse(event) {
        const response = event.detail.xhr.response;
        const data = JSON.parse(response);
        if (data.status) {
            window.location.reload(true);
        } else {
            alert(data.error);
        }
    }
</script>

<style>
    .pay img {
        height: 25px;
    }
</style>
