<script src="https://cdn.jsdelivr.net/gh/davidshimjs/qrcodejs@master/qrcode.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/clipboard@2.0.11/dist/clipboard.min.js"></script>
<style>
    #qrcode {
        display: flex;
        width: 100%;
        justify-content: center;
    }

    .address {
        width: 100%;
        border: 1px solid #eee;
        padding: 5px;
        border-radius: 4px;
    }

    .copy-botton {
        width: 100%;
    }

    .copy-botton .btn {
        width: 100%;
    }
</style>

<div style="width: 250px">
    <div id="qrcode"></div>
    <p>Please pay <span style="color: orange;">$ {$amount} USDT</span><br/>
        <b>Only USDT (Polygon) is accepted. Other tokens are non-refundable.</b><br/>
        <span>Valid till <span id="valid-till">{$validTill}</span></span></p>
    <p class="usdt-addr">
        <input id="address" class="address" value="{$address}">
    <div class="copy-botton">
        <button id="clipboard-btn" class="btn btn-primary" type="button" data-clipboard-target="#address">COPY</button>
    </div>
    </p>
</div>

<script>
    const clipboard = new ClipboardJS('#clipboard-btn')
    clipboard.on('success', () => {
        $('#clipboard-btn').text('COPIED')
        setTimeout(() => {
            $('#clipboard-btn').text('COPY')
        }, 500);
    })

    new QRCode(document.querySelector('#qrcode'), {
        text: "{$address}",
        width: 200,
        height: 200,
    })

    $('#clipboard-btn').hover(() => {
        $('#clipboard-btn').text('COPY')
    })

    window.localStorage.removeItem('whmcs_usdt_invoice');

    async function checkInvoiceStatus() {
        try {
            $('#clipboard-btn').text('UPDATING');
            const response = await fetch(window.location.href + '&act=invoice_status');
            const data = await response.json();
            const previous = JSON.parse(window.localStorage.getItem('whmcs_usdt_invoice') || '{}');
            window.localStorage.setItem('whmcs_usdt_invoice', JSON.stringify(data));

            const status = data.status.toLowerCase();
            const previousAmountIn = previous.amountin;

            if (status === 'paid' || (previousAmountIn !== undefined && previousAmountIn !== data.amountin)) {
                document.getElementById('clipboard-btn').textContent = 'Processing...';
                setTimeout(() => window.location.reload(true), 1000);
            } else if (!status) {
                alert(data.error);
            } else {
                document.getElementById('valid-till').textContent = data.valid_till;
            }

            $('#clipboard-btn').text('Updated');
            setTimeout(() => $('#clipboard-btn').text('Copy'), 1000);
        } catch (e) {
            window.location.reload(true);
        }
    }

    setInterval(checkInvoiceStatus, 15000);
</script>
