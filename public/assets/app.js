(function () {
    'use strict';

    function decode(value) {
        var bytes = Uint8Array.from(atob(value || ''), function (character) {
            return character.charCodeAt(0);
        });
        return new TextDecoder().decode(bytes);
    }

    function contentMode() {
        var field = document.querySelector('select[name="content_mode"]');
        return field ? field.value : 'woocommerce';
    }

    function updateAngleLabel() {
        var label = document.getElementById('article-angles-label');
        if (label) {
            label.textContent = contentMode() === 'server_folder'
                ? 'Portfolio article angles — one per line'
                : 'Product article angles — one per line';
        }
    }

    var modeField = document.querySelector('select[name="content_mode"]');
    if (modeField) modeField.addEventListener('change', updateAngleLabel);

    var promptButton = document.getElementById('load-recommended-prompt');
    if (promptButton) {
        promptButton.addEventListener('click', function () {
            var folder = contentMode() === 'server_folder';
            document.getElementById('system-prompt-editor').value = decode(folder ? this.dataset.folderPrompt : this.dataset.productPrompt);
            document.getElementById('article-angles-editor').value = decode(folder ? this.dataset.folderTopics : this.dataset.productTopics);
            updateAngleLabel();
            document.getElementById('recommended-prompt-status').textContent = (folder ? 'Portfolio' : 'WooCommerce') + ' prompt and article angles loaded. Click Update Website Settings to save.';
        });
    }

    var providerField = document.querySelector('select[name="ai_provider"]');
    if (providerField) {
        providerField.addEventListener('change', function () {
            var model = document.querySelector('input[name="ai_model"]');
            if (!model) return;
            model.value = {openai: 'gpt-5-mini', anthropic: 'claude-sonnet-4-6', qwen: 'qwen-plus'}[this.value] || 'gpt-5-mini';
        });
    }

}());
