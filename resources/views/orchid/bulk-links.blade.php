<div class="bg-white rounded shadow-sm p-4 mb-3">
    <div id="bulk-links-container">
        <div class="row mb-2 bulk-row">
            <div class="col-md-4">
                <input type="text" name="links[0][name]" class="form-control" placeholder="Name">
            </div>
            <div class="col-md-7">
                <input type="text" name="links[0][original_url]" class="form-control" placeholder="Original URL (https://...)">
            </div>
            <div class="col-md-1">
                <button type="button" class="btn btn-danger btn-sm remove-row" onclick="this.closest('.bulk-row').remove()">&times;</button>
            </div>
        </div>
    </div>

    <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="add-row-btn">
        + Add Row
    </button>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        let idx = 1;
        document.getElementById('add-row-btn').addEventListener('click', function () {
            const container = document.getElementById('bulk-links-container');
            const row = document.createElement('div');
            row.className = 'row mb-2 bulk-row';
            row.innerHTML = '<div class="col-md-4">' +
                '<input type="text" name="links[' + idx + '][name]" class="form-control" placeholder="Name">' +
                '</div>' +
                '<div class="col-md-7">' +
                '<input type="text" name="links[' + idx + '][original_url]" class="form-control" placeholder="Original URL (https://...)">' +
                '</div>' +
                '<div class="col-md-1">' +
                '<button type="button" class="btn btn-danger btn-sm remove-row" onclick="this.closest(\'.bulk-row\').remove()">&times;</button>' +
                '</div>';
            container.appendChild(row);
            idx++;
        });
    });
</script>
