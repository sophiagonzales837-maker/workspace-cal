<?php 
require_once '../config/db.php'; 
require_once '../config/security.php'; 
protect('Admin'); 
include 'includes/header.php'; 
?>
<div class="card">
    <h3 style="margin-top: 0; color: var(--primary-navy);">Live Team Presence</h3>
    <table class="table">
        <thead><tr><th>User</th><th>Role</th><th>Team</th><th>Presence</th><th>Last Seen</th></tr></thead>
        <tbody id="live-tracking-body">
            <tr><td colspan="5" style="text-align: center; padding: 20px; color: #888;">Loading live data...</td></tr>
        </tbody>
    </table>
</div>
<script>
function fetchLiveTimeData() {
    fetch('fetch_time_data.php')
        .then(response => response.text())
        .then(html => { document.getElementById('live-tracking-body').innerHTML = html; })
        .catch(err => console.error("Error fetching live data:", err));
}
fetchLiveTimeData();
setInterval(fetchLiveTimeData, 5000);
</script>

<script src="https://js.pusher.com/8.0/pusher.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    if (typeof Pusher !== 'undefined') {
        const adminPusher = new Pusher('41498e992485a94e78b9', {
            cluster: 'ap2',
            forceTLS: true
        });
        
        const adminChannel = adminPusher.subscribe('admin-updates');
        
        adminChannel.bind('refresh-tracker', function() {
            // Pure Vanilla JS to silently fetch the updated rows and inject them
            fetch('fetch_time_data.php')
                .then(response => response.text())
                .then(html => {
                    const tbody = document.querySelector('table tbody');
                    if (tbody) tbody.innerHTML = html;
                }).catch(err => console.error("Error fetching updates:", err));
        });
    }
});
</script>
<?php include 'includes/footer.php'; ?>
