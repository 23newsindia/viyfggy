document.addEventListener('DOMContentLoaded', function() {
    // Toggle between list and editor views
    const addNewBtn = document.getElementById('ctd-add-new');
    const rulesList = document.querySelector('.ctd-rules-list');
    const ruleEditor = document.querySelector('.ctd-rule-editor');

    if (addNewBtn && rulesList && ruleEditor) {
        addNewBtn.addEventListener('click', function() {
            rulesList.style.display = 'none';
            ruleEditor.style.display = 'block';
            resetEditor();
        });

        document.getElementById('ctd-cancel-edit').addEventListener('click', function() {
            rulesList.style.display = 'block';
            ruleEditor.style.display = 'none';
        });
    }

    function resetEditor() {
        document.getElementById('ctd-rule-name').value = '';
        document.getElementById('ctd-quantity').value = '2';
        document.getElementById('ctd-discount-price').value = '999';
        document.querySelector('.ctd-rule-editor').dataset.id = '';
        
        // Reset checkboxes
        document.querySelectorAll('input[name="categories[]"]').forEach(cb => cb.checked = false);
        document.querySelectorAll('input[name="excluded_products[]"]').forEach(cb => cb.checked = false);
    }

    // Save rule
    document.getElementById('ctd-save-rule')?.addEventListener('click', async function(e) {
        e.preventDefault();
        
        const name = document.getElementById('ctd-rule-name').value;
        const quantity = document.getElementById('ctd-quantity').value;
        const discountPrice = document.getElementById('ctd-discount-price').value;
        
        if (!name || !quantity || !discountPrice) {
            alert('Please fill in all required fields!');
            return;
        }
        
        // Get selected categories
        const categories = [];
        document.querySelectorAll('input[name="categories[]"]:checked').forEach(cb => {
            categories.push(cb.value);
        });
        
        if (categories.length === 0) {
            alert('Please select at least one category!');
            return;
        }
        
        // Get excluded products
        const excludedProducts = [];
        document.querySelectorAll('input[name="excluded_products[]"]:checked').forEach(cb => {
            excludedProducts.push(cb.value);
        });
        
        // Show loading state
        const saveBtn = this;
        const originalText = saveBtn.textContent;
        saveBtn.textContent = 'Saving...';
        saveBtn.disabled = true;
        
        try {
            const formData = new FormData();
            formData.append('action', 'ctd_save_rule');
            formData.append('nonce', ctd_admin_vars.nonce);
            formData.append('name', name);
            formData.append('categories', JSON.stringify(categories));
            formData.append('excluded_products', JSON.stringify(excludedProducts));
            formData.append('quantity', quantity);
            formData.append('discount_price', discountPrice);

            const ruleId = document.querySelector('.ctd-rule-editor').dataset.id;
            if (ruleId) {
                formData.append('rule_id', ruleId);
            }

            const response = await fetch(ctd_admin_vars.ajax_url, {
                method: 'POST',
                body: formData
            });

            const data = await response.json();
            
            if (data.success) {
                alert('Rule saved successfully!');
                window.location.reload();
            } else {
                throw new Error(data.data?.message || 'Failed to save rule');
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Failed to save rule: ' + error.message);
        } finally {
            saveBtn.textContent = originalText;
            saveBtn.disabled = false;
        }
    });

    // Edit rule
    document.addEventListener('click', async function(e) {
        if (e.target.classList.contains('ctd-edit-rule')) {
            e.preventDefault();
            
            const ruleId = e.target.dataset.id;
            
            try {
                const formData = new FormData();
                formData.append('action', 'ctd_get_rule');
                formData.append('nonce', ctd_admin_vars.nonce);
                formData.append('id', ruleId);

                const response = await fetch(ctd_admin_vars.ajax_url, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();
                
                if (data.success) {
                    const rule = data.data;
                    
                    // Switch to editor view
                    rulesList.style.display = 'none';
                    ruleEditor.style.display = 'block';
                    
                    // Set rule ID
                    ruleEditor.dataset.id = rule.rule_id;
                    
                    // Fill in form data
                    document.getElementById('ctd-rule-name').value = rule.name;
                    document.getElementById('ctd-quantity').value = rule.quantity;
                    document.getElementById('ctd-discount-price').value = rule.discount_price;
                    
                    // Parse categories and excluded products
                    let categories = [];
                    let excludedProducts = [];
                    
                    try {
                        categories = JSON.parse(rule.categories);
                        excludedProducts = JSON.parse(rule.excluded_products);
                    } catch (e) {
                        console.error('Failed to parse data:', e);
                    }
                    
                    // Reset all checkboxes first
                    document.querySelectorAll('input[name="categories[]"]').forEach(cb => cb.checked = false);
                    document.querySelectorAll('input[name="excluded_products[]"]').forEach(cb => cb.checked = false);
                    
                    // Set categories
                    categories.forEach(catId => {
                        const checkbox = document.querySelector(`input[name="categories[]"][value="${catId}"]`);
                        if (checkbox) {
                            checkbox.checked = true;
                        }
                    });
                    
                    // Set excluded products
                    excludedProducts.forEach(productId => {
                        const checkbox = document.querySelector(`input[name="excluded_products[]"][value="${productId}"]`);
                        if (checkbox) {
                            checkbox.checked = true;
                        }
                    });
                } else {
                    throw new Error(data.data?.message || 'Failed to load rule');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to load rule: ' + error.message);
            }
        }
    });

    // Delete rule
    document.addEventListener('click', async function(e) {
        if (e.target.classList.contains('ctd-delete-rule')) {
            e.preventDefault();
            
            if (!confirm('Are you sure you want to delete this rule?')) {
                return;
            }
            
            const ruleId = e.target.dataset.id;
            
            try {
                const formData = new FormData();
                formData.append('action', 'ctd_delete_rule');
                formData.append('nonce', ctd_admin_vars.nonce);
                formData.append('id', ruleId);

                const response = await fetch(ctd_admin_vars.ajax_url, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();
                
                if (data.success) {
                    alert('Rule deleted successfully!');
                    window.location.reload();
                } else {
                    throw new Error(data.data?.message || 'Failed to delete rule');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to delete rule: ' + error.message);
            }
        }
    });
});
