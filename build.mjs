import fs from 'fs';
const log = (...a)=>console.log(...a);

// --- postcodes needed ---
const rows = fs.readFileSync('/tmp/cb/postcodes.tsv','utf8').trim().split('\n').map(l=>l.split('\t'));
const need = { fr:new Set(), be:new Set() };
for(const [c,pc] of rows){ const p=(pc||'').trim(); if(!p) continue; if(c==='8') need.fr.add(p); else if(c==='3') need.be.add(p); }
log('CP a geocoder: FR=%d BE=%d', need.fr.size, need.be.size);

const postcodes = {};

// --- FR: base communes en masse ---
log('Fetch communes FR...');
const communes = await (await fetch('https://geo.api.gouv.fr/communes?fields=codesPostaux,centre&format=json&geometry=centre')).json();
log('communes recues: %d', communes.length);
const acc = {};
for(const c of communes){
  if(!c.centre || !c.centre.coordinates) continue;
  const [lon,lat] = c.centre.coordinates;
  for(const pc of (c.codesPostaux||[])){
    if(need.fr.has(pc)){ (acc[pc] ||= [0,0,0]); acc[pc][0]+=lat; acc[pc][1]+=lon; acc[pc][2]++; }
  }
}
for(const pc in acc){ const a=acc[pc]; postcodes['8|'+pc] = [ +(a[0]/a[2]).toFixed(4), +(a[1]/a[2]).toFixed(4) ]; }
log('FR geocodes: %d / %d', Object.keys(acc).length, need.fr.size);

// --- BE: zippopotam ---
log('Geocode BE (%d)...', need.be.size);
let beOk=0;
for(const pc of need.be){
  try{
    const r = await (await fetch('https://api.zippopotam.us/be/'+pc)).json();
    const p = r.places && r.places[0];
    if(p){ postcodes['3|'+pc] = [ +(+p.latitude).toFixed(4), +(+p.longitude).toFixed(4) ]; beOk++; }
  }catch(e){}
}
log('BE geocodes: %d / %d', beOk, need.be.size);

// --- outlines ---
log('Fetch outlines...');
const frGeo = await (await fetch('https://raw.githubusercontent.com/gregoiredavid/france-geojson/master/metropole.geojson')).json();
const beGeo = await (await fetch('https://raw.githubusercontent.com/johan/world.geo.json/master/countries/BEL.geo.json')).json();

function rings(geo){
  const out=[];
  const poly = coords => { for(const ring of coords) out.push(ring); };
  const handle = g => { if(!g) return; if(g.type==='Polygon') poly(g.coordinates); else if(g.type==='MultiPolygon') for(const p of g.coordinates) poly(p); };
  if(geo.type==='FeatureCollection') for(const f of geo.features) handle(f.geometry);
  else if(geo.type==='Feature') handle(geo.geometry);
  else handle(geo);
  return out;
}
// simplification par distance mini (rendu stylise, lisse)
function simplify(ring, eps){
  if(ring.length<6) return ring;
  const o=[ring[0]];
  for(let i=1;i<ring.length-1;i++){ const [x,y]=ring[i]; const [px,py]=o[o.length-1]; if(Math.hypot(x-px,y-py)>eps) o.push(ring[i]); }
  o.push(ring[ring.length-1]);
  return o;
}
let polys = [...rings(frGeo), ...rings(beGeo)]
  .filter(r => r.length>10)
  .map(r => simplify(r, 0.018))
  .filter(r => r.length>=5)
  .map(r => r.map(([lon,lat]) => [ +lon.toFixed(4), +lat.toFixed(4) ]));

let minLon=999,minLat=999,maxLon=-999,maxLat=-999;
for(const r of polys) for(const [lon,lat] of r){ minLon=Math.min(minLon,lon); maxLon=Math.max(maxLon,lon); minLat=Math.min(minLat,lat); maxLat=Math.max(maxLat,lat); }
log('polys: %d, points totaux: %d, bounds lon[%s,%s] lat[%s,%s]',
  polys.length, polys.reduce((s,r)=>s+r.length,0), minLon,maxLon,minLat,maxLat);

fs.writeFileSync('/tmp/cb/outline.json', JSON.stringify({polys, bounds:[minLon,minLat,maxLon,maxLat]}));
fs.writeFileSync('/tmp/cb/postcodes.json', JSON.stringify(postcodes));
log('Ecrit outline.json (%d o) + postcodes.json (%d entrees)',
  fs.statSync('/tmp/cb/outline.json').size, Object.keys(postcodes).length);
