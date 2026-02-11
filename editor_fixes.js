// Perbaikan untuk Editor Search dan Save Functionality
// Tambahkan script ini setelah jQuery dimuat

$(document).ready(function() {
    // Perbaikan untuk search functionality
    let searchMatches = [];
    let currentSearchIndex = -1;
    
    // Override performSearch function
    window.performSearch = function() {
        const searchTerm = $('#editor-search-input').val();
        const textarea = $('#editor-textarea');
        const content = textarea.val();
        const status = $('#editor-search-status');
        
        // Clear previous search
        searchMatches = [];
        currentSearchIndex = -1;
        status.text('');
        
        if (!searchTerm) {
            return;
        }
        
        // Find all matches - escape special regex characters properly
        const escapedTerm = searchTerm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const regex = new RegExp(escapedTerm, 'gi');
        let match;
        
        while ((match = regex.exec(content)) !== null) {
            searchMatches.push({
                start: match.index,
                end: match.index + match[0].length,
                text: match[0]
            });
            
            // Prevent infinite loop for zero-length matches
            if (match.index === regex.lastIndex) {
                regex.lastIndex++;
            }
        }
        
        if (searchMatches.length > 0) {
            currentSearchIndex = 0;
            highlightCurrentMatch();
            updateSearchStatus();
        } else {
            status.text('No matches found');
        }
    };
    
    // Override highlightCurrentMatch function
    window.highlightCurrentMatch = function() {
        if (currentSearchIndex >= 0 && currentSearchIndex < searchMatches.length) {
            const textarea = $('#editor-textarea')[0];
            const match = searchMatches[currentSearchIndex];
            
            textarea.focus();
            textarea.setSelectionRange(match.start, match.end);
            textarea.scrollTop = textarea.scrollHeight * (match.start / textarea.value.length) - textarea.clientHeight / 2;
        }
    };
    
    // Override updateSearchStatus function
    window.updateSearchStatus = function() {
        const status = $('#editor-search-status');
        if (searchMatches.length > 0) {
            status.text(`${currentSearchIndex + 1} of ${searchMatches.length}`);
        } else {
            status.text('No matches');
        }
    };
    
    // Perbaikan event handlers untuk search next/prev
    $('#editor-search-next').off('click').on('click', function() {
        if (searchMatches.length > 0) {
            currentSearchIndex = (currentSearchIndex + 1) % searchMatches.length;
            highlightCurrentMatch();
            updateSearchStatus();
        } else {
            Swal.fire({
                title: 'No Results',
                text: 'Please perform a search first',
                icon: 'info',
                timer: 2000,
                showConfirmButton: false
            });
        }
    });
    
    $('#editor-search-prev').off('click').on('click', function() {
        if (searchMatches.length > 0) {
            currentSearchIndex = currentSearchIndex <= 0 ? searchMatches.length - 1 : currentSearchIndex - 1;
            highlightCurrentMatch();
            updateSearchStatus();
        } else {
            Swal.fire({
                title: 'No Results',
                text: 'Please perform a search first',
                icon: 'info',
                timer: 2000,
                showConfirmButton: false
            });
        }
    });
    
    // Perbaikan untuk save functionality
    $('#editor-save-btn').off('click').on('click', function() {
        const content = $('#editor-textarea').val();
        const fileName = window.previewFileName;
        const saveBtn = $(this);
        const originalText = saveBtn.html();
        
        if (!fileName) {
            Swal.fire({
                title: 'Error',
                text: 'File name not found',
                icon: 'error'
            });
            return;
        }
        
        // Show saving state
        saveBtn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');
        
        // Get current path
        const urlParams = new URLSearchParams(window.location.search);
        const currentPath = urlParams.get('p') || '';
        
        // Prepare save data
        const saveData = {
            type: 'save',
            content: content,
            token: window.csrf,
            ajax: true
        };
        
        // Create edit URL
        const editUrl = '?p=' + encodeURIComponent(currentPath) + '&edit=' + encodeURIComponent(fileName);
        
        // Send AJAX request to save file
        $.ajax({
            url: editUrl,
            type: 'POST',
            data: JSON.stringify(saveData),
            contentType: 'application/json; charset=utf-8',
            success: function(response) {
                Swal.fire({
                    title: 'Success!',
                    text: 'File saved successfully',
                    icon: 'success',
                    timer: 2000,
                    showConfirmButton: false
                });
                
                saveBtn.prop('disabled', false).html('<i class="fa fa-check"></i> Saved');
                
                // Reset button text after 2 seconds
                setTimeout(function() {
                    saveBtn.html(originalText);
                }, 2000);
            },
            error: function(xhr, status, error) {
                let errorMsg = 'Error saving file';
                if (xhr.responseText) {
                    errorMsg += ': ' + xhr.responseText;
                }
                
                Swal.fire({
                    title: 'Error',
                    text: errorMsg,
                    icon: 'error'
                });
                
                saveBtn.prop('disabled', false).html(originalText);
            }
        });
    });
    
    // Keyboard shortcuts
    $(document).on('keydown', function(e) {
        // Ctrl+G for next search result
        if (e.ctrlKey && e.key === 'g' && !e.shiftKey) {
            e.preventDefault();
            $('#editor-search-next').click();
        }
        // Ctrl+Shift+G for previous search result
        else if (e.ctrlKey && e.shiftKey && e.key === 'G') {
            e.preventDefault();
            $('#editor-search-prev').click();
        }
        // Ctrl+S for save
        else if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            if ($('#editor-save-btn').is(':visible')) {
                $('#editor-save-btn').click();
            }
        }
        // Ctrl+F for search
        else if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            $('#editor-search-input').focus();
        }
    });
});