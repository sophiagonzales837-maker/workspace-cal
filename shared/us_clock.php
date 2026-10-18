<a href="us_clock_map.php" class="tz-bar-shared" style="text-decoration: none; display: flex; flex-direction: row; gap: 10px; align-items: center; cursor: pointer; transition: transform 0.2s ease;">
    
    <div style="background: #1abc9c80; padding: 6px 14px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.15); text-align: center; min-width: 95px;">
        <div style="font-size: 10px; color: #ffffff; font-weight: 700; margin-bottom: 2px; letter-spacing: 0.5px;">EASTERN (EST)</div>
        <div id="clock-et" style="font-size: 14px; font-weight: 800; color: white; font-family: monospace;">--:--</div>
    </div>
    
    <div style="background: #f39d12a7; padding: 6px 14px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.15); text-align: center; min-width: 95px;">
        <div style="font-size: 10px; color: #ffffff; font-weight: 700; margin-bottom: 2px; letter-spacing: 0.5px;">CENTRAL (CST)</div>
        <div id="clock-ct" style="font-size: 14px; font-weight: 800; color: white; font-family: monospace;">--:--</div>
    </div>
    
    <div style="background: #e74d3c94; padding: 6px 14px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.15); text-align: center; min-width: 95px;">
        <div style="font-size: 10px; color: #ffffff; font-weight: 700; margin-bottom: 2px; letter-spacing: 0.5px;">MOUNTAIN (MST)</div>
        <div id="clock-mt" style="font-size: 14px; font-weight: 800; color: white; font-family: monospace;">--:--</div>
    </div>
    
    <div style="background: #34495e8b; padding: 6px 14px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.15); text-align: center; min-width: 95px;">
        <div style="font-size: 10px; color: #ffffff; font-weight: 700; margin-bottom: 2px; letter-spacing: 0.5px;">PACIFIC (PST)</div>
        <div id="clock-pt" style="font-size: 14px; font-weight: 800; color: white; font-family: monospace;">--:--</div>
    </div>
    
</a>

<style>
    .tz-bar-shared:hover {
        transform: translateY(-2px);
    }
</style>

<script>
function updateUSClocks() {
    const options = { hour: '2-digit', minute: '2-digit', hour12: true };
    const now = new Date();
    
    const elPt = document.getElementById('clock-pt');
    const elMt = document.getElementById('clock-mt');
    const elCt = document.getElementById('clock-ct');
    const elEt = document.getElementById('clock-et');
    
    // Only update if elements exist on the page to prevent console errors
    if(elPt) elPt.innerText = now.toLocaleTimeString('en-US', { ...options, timeZone: 'America/Los_Angeles' });
    if(elMt) elMt.innerText = now.toLocaleTimeString('en-US', { ...options, timeZone: 'America/Denver' });
    if(elCt) elCt.innerText = now.toLocaleTimeString('en-US', { ...options, timeZone: 'America/Chicago' });
    if(elEt) elEt.innerText = now.toLocaleTimeString('en-US', { ...options, timeZone: 'America/New_York' });
}

setInterval(updateUSClocks, 1000);
updateUSClocks();
</script>