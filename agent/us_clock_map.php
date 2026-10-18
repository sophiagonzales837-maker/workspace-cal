<?php 
require_once '../config/db.php'; 
require_once '../config/security.php'; 
if(!isset($_SESSION['user_id'])) { session_write_close(); header("Location: ../auth/login.php"); exit(); }
include 'includes/header.php'; 
?>

<style>
    .time-card-container { display: flex; gap: 15px; justify-content: center; margin-bottom: 30px; }
    .time-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); text-align: center; flex: 1; max-width: 220px; border-top: 5px solid #ccc; transition: transform 0.2s; }
    .time-card:hover { transform: translateY(-5px); box-shadow: 0 8px 20px rgba(0,0,0,0.1); }
    .time-card.pt { border-top-color: #34495E; }
    .time-card.mt { border-top-color: #E74C3C; }
    .time-card.ct { border-top-color: #F39C12; }
    .time-card.et { border-top-color: #1ABC9C; }
    .tc-label { font-size: 12px; font-weight: bold; color: #888; text-transform: uppercase; margin-bottom: 8px; letter-spacing: 1px; }
    .tc-time { font-size: 28px; font-weight: 800; color: var(--primary-navy); font-variant-numeric: tabular-nums; }
    .tc-date { font-size: 12px; color: #a0aec0; margin-top: 5px; font-weight: 500; }
    .map-wrapper { background: white; padding: 30px 20px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); position: relative; min-height: 500px; }
    #d3_map { width: 100%; max-width: 950px; margin: 0 auto; display: block; position: relative; }
    #map-loading { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-size: 16px; color: #666; font-weight: bold; text-align: center; width: 100%; }
    .state-path { transition: opacity 0.2s; cursor: pointer; }
    .state-path:hover { opacity: 0.8; }
    .state-label { font-family: 'Inter', sans-serif; font-size: 11px; font-weight: bold; fill: white; pointer-events: none; text-anchor: middle; }
    .map-tooltip { position: absolute; text-align: center; padding: 12px 20px; font-family: 'Inter', sans-serif; background: #111827; color: white; border-radius: 8px; pointer-events: none; opacity: 0; box-shadow: 0 8px 25px rgba(0,0,0,0.3); z-index: 1000; transform: translate(-50%, -100%); margin-top: -15px; transition: opacity 0.2s; }
    .map-tooltip .tt-state { font-size: 13px; font-weight: bold; margin-bottom: 5px; color: #a0aec0; text-transform: uppercase; letter-spacing: 1px;}
    .map-tooltip .tt-time { font-size: 22px; font-weight: 800; color: #fbbf24; font-variant-numeric: tabular-nums; }
</style>

<div class="card" style="padding: 40px; background: #f8f9fa;">
    <div style="text-align: center; margin-bottom: 30px;">
        <h2 style="color: var(--primary-navy); margin: 0; font-size: 28px;">United States Time Zones</h2>
        <p style="color: #888; font-size: 14px; margin-top: 5px;">Live operational clocks across all major US regions.</p>
    </div>

    <div class="time-card-container">
        <div class="time-card pt"><div class="tc-label">Pacific (PT)</div><div class="tc-time" id="card-pt">--:--</div><div class="tc-date" id="date-pt">Loading...</div></div>
        <div class="time-card mt"><div class="tc-label">Mountain (MT)</div><div class="tc-time" id="card-mt">--:--</div><div class="tc-date" id="date-mt">Loading...</div></div>
        <div class="time-card ct"><div class="tc-label">Central (CT)</div><div class="tc-time" id="card-ct">--:--</div><div class="tc-date" id="date-ct">Loading...</div></div>
        <div class="time-card et"><div class="tc-label">Eastern (ET)</div><div class="tc-time" id="card-et">--:--</div><div class="tc-date" id="date-et">Loading...</div></div>
    </div>

    <div class="map-wrapper">
        <div id="d3_map">
            <div id="map-loading">⏳ Loading Interactive Map...<br><span style="font-size: 12px; font-weight: normal;">Fetching geographic data</span></div>
        </div>
    </div>
</div>

<div id="tooltip" class="map-tooltip">
    <div class="tt-state" id="tt-state">State Name</div>
    <div class="tt-time" id="tt-time">--:--</div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/d3/7.8.5/d3.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/topojson/3.0.2/topojson.min.js"></script>

<script>
const stateData = {
    "Washington": { abbr: "WA", tz: "America/Los_Angeles", color: "#34495E" }, "Oregon": { abbr: "OR", tz: "America/Los_Angeles", color: "#34495E" }, "California": { abbr: "CA", tz: "America/Los_Angeles", color: "#34495E" }, "Nevada": { abbr: "NV", tz: "America/Los_Angeles", color: "#34495E" },
    "Idaho": { abbr: "ID", tz: "America/Denver", color: "#E74C3C" }, "Montana": { abbr: "MT", tz: "America/Denver", color: "#E74C3C" }, "Wyoming": { abbr: "WY", tz: "America/Denver", color: "#E74C3C" }, "Utah": { abbr: "UT", tz: "America/Denver", color: "#E74C3C" }, "Colorado": { abbr: "CO", tz: "America/Denver", color: "#E74C3C" }, "Arizona": { abbr: "AZ", tz: "America/Phoenix", color: "#E74C3C" }, "New Mexico": { abbr: "NM", tz: "America/Denver", color: "#E74C3C" },
    "North Dakota": { abbr: "ND", tz: "America/Chicago", color: "#F39C12" }, "South Dakota": { abbr: "SD", tz: "America/Chicago", color: "#F39C12" }, "Nebraska": { abbr: "NE", tz: "America/Chicago", color: "#F39C12" }, "Kansas": { abbr: "KS", tz: "America/Chicago", color: "#F39C12" }, "Oklahoma": { abbr: "OK", tz: "America/Chicago", color: "#F39C12" }, "Texas": { abbr: "TX", tz: "America/Chicago", color: "#F39C12" }, "Minnesota": { abbr: "MN", tz: "America/Chicago", color: "#F39C12" }, "Iowa": { abbr: "IA", tz: "America/Chicago", color: "#F39C12" }, "Missouri": { abbr: "MO", tz: "America/Chicago", color: "#F39C12" }, "Arkansas": { abbr: "AR", tz: "America/Chicago", color: "#F39C12" }, "Louisiana": { abbr: "LA", tz: "America/Chicago", color: "#F39C12" }, "Wisconsin": { abbr: "WI", tz: "America/Chicago", color: "#F39C12" }, "Illinois": { abbr: "IL", tz: "America/Chicago", color: "#F39C12" }, "Tennessee": { abbr: "TN", tz: "America/Chicago", color: "#F39C12" }, "Mississippi": { abbr: "MS", tz: "America/Chicago", color: "#F39C12" }, "Alabama": { abbr: "AL", tz: "America/Chicago", color: "#F39C12" },
    "Michigan": { abbr: "MI", tz: "America/New_York", color: "#1ABC9C" }, "Indiana": { abbr: "IN", tz: "America/Indiana/Indianapolis", color: "#1ABC9C" }, "Ohio": { abbr: "OH", tz: "America/New_York", color: "#1ABC9C" }, "Kentucky": { abbr: "KY", tz: "America/New_York", color: "#1ABC9C" }, "Georgia": { abbr: "GA", tz: "America/New_York", color: "#1ABC9C" }, "Florida": { abbr: "FL", tz: "America/New_York", color: "#1ABC9C" }, "New York": { abbr: "NY", tz: "America/New_York", color: "#1ABC9C" }, "Pennsylvania": { abbr: "PA", tz: "America/New_York", color: "#1ABC9C" }, "West Virginia": { abbr: "WV", tz: "America/New_York", color: "#1ABC9C" }, "Virginia": { abbr: "VA", tz: "America/New_York", color: "#1ABC9C" }, "North Carolina": { abbr: "NC", tz: "America/New_York", color: "#1ABC9C" }, "South Carolina": { abbr: "SC", tz: "America/New_York", color: "#1ABC9C" }, "Maryland": { abbr: "MD", tz: "America/New_York", color: "#1ABC9C" }, "Delaware": { abbr: "DE", tz: "America/New_York", color: "#1ABC9C" }, "New Jersey": { abbr: "NJ", tz: "America/New_York", color: "#1ABC9C" }, "Connecticut": { abbr: "CT", tz: "America/New_York", color: "#1ABC9C" }, "Rhode Island": { abbr: "RI", tz: "America/New_York", color: "#1ABC9C" }, "Massachusetts": { abbr: "MA", tz: "America/New_York", color: "#1ABC9C" }, "Vermont": { abbr: "VT", tz: "America/New_York", color: "#1ABC9C" }, "New Hampshire": { abbr: "NH", tz: "America/New_York", color: "#1ABC9C" }, "Maine": { abbr: "ME", tz: "America/New_York", color: "#1ABC9C" }
};

let hoveredStateTz = null;
const width = 975; const height = 610;
const svg = d3.select("#d3_map").append("svg").attr("viewBox", `0 0 ${width} ${height}`).style("width", "100%").style("height", "auto");
const path = d3.geoPath();
const tooltip = d3.select("#tooltip");

d3.json("https://cdn.jsdelivr.net/npm/us-atlas@3/states-albers-10m.json").then(us => {
    document.getElementById("map-loading").style.display = "none";
    const states = topojson.feature(us, us.objects.states).features;

    svg.append("g").selectAll("path").data(states).join("path")
        .attr("class", "state-path").attr("d", path)
        .attr("fill", d => stateData[d.properties.name] ? stateData[d.properties.name].color : "#e0e0e0")
        .attr("stroke", "white").attr("stroke-width", 0.8)
        .on("mousemove", function(event, d) {
            const stateInfo = stateData[d.properties.name];
            if (!stateInfo) return;
            hoveredStateTz = stateInfo.tz;
            document.getElementById('tt-state').innerText = d.properties.name;
            d3.select(this).attr("stroke", "#111827").attr("stroke-width", 2);
            tooltip.style("opacity", 1).style("left", event.pageX + "px").style("top", event.pageY + "px");
        })
        .on("mouseout", function() {
            hoveredStateTz = null;
            d3.select(this).attr("stroke", "white").attr("stroke-width", 0.8);
            tooltip.style("opacity", 0);
        });

    svg.append("g").selectAll("text").data(states).join("text")
        .attr("class", "state-label")
        .attr("transform", d => {
            const c = path.centroid(d);
            if (!c || isNaN(c[0]) || isNaN(c[1])) return "translate(-1000,-1000)"; 
            return `translate(${c[0]}, ${c[1]})`;
        })
        .attr("dy", "0.35em")
        .text(d => {
            const hideLabels = ["RI", "DE", "DC", "MD", "NJ", "CT", "MA", "VT", "NH"];
            const info = stateData[d.properties.name];
            return (info && !hideLabels.includes(info.abbr)) ? info.abbr : "";
        });

}).catch(error => {
    document.getElementById('map-loading').innerHTML = "<span style='color:#dc3545;'>⚠️ Error loading map data.</span>";
});

function updateClocks() {
    const tOpt = { hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true };
    const dOpt = { weekday: 'short', month: 'short', day: 'numeric' };
    const now = new Date();
    const zones = [
        { id: 'pt', tz: 'America/Los_Angeles' }, { id: 'mt', tz: 'America/Denver' },
        { id: 'ct', tz: 'America/Chicago' }, { id: 'et', tz: 'America/New_York' }
    ];
    zones.forEach(z => {
        document.getElementById(`card-${z.id}`).innerText = now.toLocaleTimeString('en-US', { ...tOpt, timeZone: z.tz });
        document.getElementById(`date-${z.id}`).innerText = now.toLocaleDateString('en-US', { ...dOpt, timeZone: z.tz }).toUpperCase();
    });
    if (hoveredStateTz) {
        document.getElementById('tt-time').innerText = now.toLocaleTimeString('en-US', { ...tOpt, timeZone: hoveredStateTz });
    }
}
setInterval(updateClocks, 1000); updateClocks();
</script>

<?php include 'includes/footer.php'; ?>
