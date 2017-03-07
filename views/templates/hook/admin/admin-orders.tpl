<script type="text/javascript">
    var AdminOrders = function (settings) {

        var isPS14 = settings.isPS14;
        var isPS15 = settings.isPS15;
        var notHandledOrders = settings.notHandledOrders;
        var requestUrl = settings.requestUrl;

        var orderBoxName = 'orderBox[]';
        var orderBox = 'input[name="' + orderBoxName + '"]';
        var orderBoxChecked = orderBox + ':checked';
        var $submitSellermaniaButton = $('#sellermaniaRequestOrder');

        var requestOrders = function () {
            $.ajax({
                url: requestUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    orderBox: $(orderBoxChecked).map(function() {
                        return this.value;
                    }).get()
                }
            }).success(function() {
                location.reload();
            })
        };

        var handleSubmitButton = function () {
            if ($(orderBoxChecked).length) {
                $submitSellermaniaButton.prop('disabled', false);
            } else {
                $submitSellermaniaButton.prop('disabled', true);
            }
        };

        this.initialize = function () {
             // Add checkbox for each order
            if (isPS14 || isPS15) {
                $('table.table > thead > tr:first-child').prepend('<td><input type="checkbox" name="toggleAllSellermaniaOrder"></td>');
                $('table.table > thead > tr:nth-child(2)').prepend('<td></td>');

                $('table.table > tbody > tr').each(function() {

                    var orderId = $(this).find('td:nth-child(2)').text();
                    var column;
                    if (notHandledOrders.indexOf(orderId) >= 0) {
                        column = '<td><input type="checkbox" name="' + orderBoxName + '" value="' + orderId + '"></td>';
                    } else {
                        column = '<td></td>';
                    }
                    $(this).prepend(column);
                });

                $submitSellermaniaButton.insertBefore('table.table');
            }

            $(orderBox).on('click', function () {
                handleSubmitButton();
            });

            $submitSellermaniaButton.on('click', function() {
                requestOrders();
                return false;
            });
        };
    };

    $(function() {
        (new AdminOrders({
            isPS14: {$isPs14|json_encode},
            isPS15: {$isPs15|json_encode},
            notHandledOrders: {$notHandledOrder|json_encode},
            requestUrl: '{$requestUrl}'
        })).initialize();
    });

</script>
<div>
    {l s='Update all current order with the current order state'}
</div>
<input type="submit" id="sellermaniaRequestOrder" disabled="disabled" value="Send Sellermania orders">
<p>{l s='Select at least one order to submit them to Sellermania'}</p>
