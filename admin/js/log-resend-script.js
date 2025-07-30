jQuery(document).ready(function($) {
    // Manipulador para os botões de reenvio na tabela de logs
    // Usa delegação de eventos no container do accordion
    $('#logs-accordion').on('click', '.wc-educpay-log-resend-button', function(e) {
        e.preventDefault();
        e.stopPropagation(); // Impede que o clique feche/abra o accordion

        var $button = $(this);
        var $actionsDiv = $button.parent('.log-actions'); // Pega o container das ações
        var $spinner = $actionsDiv.find('.spinner');
        var $statusSpan = $actionsDiv.find('.resend-status');

        var logId = $button.data('log-id');
        var orderId = $button.data('order-id');
        var productId = $button.data('product-id');
        var participantIndex = $button.data('participant-index');
        // Pega o nonce GERAL da página (que foi adicionado em display_logs)
        var nonce = $('#wc_educpay_resend_log_nonce').val();

        // Validação básica no JS
        if (!logId || !orderId || !productId || !participantIndex || !nonce) {
            $statusSpan.css('color', 'red').text('Erro: Dados faltando no botão.');
            console.error("Resend button missing data attributes or nonce field not found.");
            return;
        }

        // Desabilita botão e mostra loading
        $button.prop('disabled', true);
        $spinner.addClass('is-active').css({'display':'inline-block', 'visibility':'visible', 'opacity':1});
        $statusSpan.css('color', 'inherit').text(wcEducpayLogResend.resending_text || 'Reagendando...'); // Usa texto localizado

        // Requisição AJAX
        $.ajax({
            url: wcEducpayLogResend.ajax_url, // URL passada via wp_localize_script
            type: 'POST',
            data: {
                action: 'wc_educpay_resend_log_entry', // Ação AJAX correta
                log_id: logId,
                order_id: orderId,
                product_id: productId,
                participant_index: participantIndex,
                nonce: nonce // Envia o nonce para verificação
            },
            success: function(response) {
                // Sempre reabilita o botão e esconde o spinner
                $button.prop('disabled', false);
                $spinner.removeClass('is-active').css({'display':'none', 'visibility':'hidden', 'opacity':0});

                if (response.success) {
                    $statusSpan.css('color', 'green').text('✔️ ' + response.data);
                    // Muda o texto do botão temporariamente para indicar sucesso
                     $button.text("Reagendado!");
                     setTimeout(function() {
                          $statusSpan.text('');
                          // Volta texto original (pego do localize)
                          $button.html('<span class="dashicons dashicons-controls-repeat" style="vertical-align: text-bottom;"></span> ' + (wcEducpayLogResend.resend_text || 'Reenviar'));
                     }, 5000); // Limpa após 5 segundos
                } else {
                    // Exibe a mensagem de erro retornada pelo PHP
                    var errorMessage = response.data || 'Ocorreu um erro desconhecido.';
                    $statusSpan.css('color', 'red').text('❌ ' + errorMessage);
                     // Mantém a mensagem de erro visível por mais tempo
                     setTimeout(function() {
                          $statusSpan.text('');
                     }, 8000); // Limpa após 8 segundos
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                $button.prop('disabled', false); // Reabilita
                $spinner.removeClass('is-active').css({'display':'none', 'visibility':'hidden', 'opacity':0});
                $statusSpan.css('color', 'red').text('❌ Erro AJAX: ' .concat(textStatus)); // Concatenação segura
                console.error("AJAX Error:", textStatus, errorThrown, jqXHR);
            }
        });
    });
});