# -*- coding: utf-8 -*-
"""
_build_rose.py — genera le pagine SEO delle rose (IT in rose/, EN in en/rose/) + indici + sitemap.xml
Uso: python _build_rose.py   (richiede node nel PATH; dati letti da game-data.js)
Rigenerare a ogni modifica dei dataset.
"""
import re, json, os, sys, unicodedata, subprocess, hashlib, tempfile
from datetime import date

BASE = os.path.dirname(os.path.abspath(__file__))
SITE = 'https://decempionz.com'
TODAY = date.today().isoformat()

def _git_dirty():
    try:
        out = subprocess.run(['git','status','--porcelain'],cwd=BASE,capture_output=True,text=True,timeout=60)
    except Exception:
        return None
    if out.returncode != 0:
        return None
    return {l[3:].strip().strip('"') for l in out.stdout.splitlines()}

_DIRTY = None
def lastmod(rel):
    """Data reale dell'ultima modifica del file (ultimo commit git); oggi se il file e' modificato
    ma non ancora committato o se git non e' disponibile (es. copie temporanee dei test)."""
    global _DIRTY
    if rel == '' or rel.endswith('/'):
        rel += 'index.html'
    if _DIRTY is None:
        dirty = _git_dirty()
        _DIRTY = False if dirty is None else dirty
    if _DIRTY is False or rel in _DIRTY:
        return TODAY
    try:
        out = subprocess.run(['git','log','-1','--format=%cs','--',rel],cwd=BASE,capture_output=True,text=True,timeout=20)
    except Exception:
        return TODAY
    return out.stdout.strip() if out.returncode == 0 and out.stdout.strip() else TODAY
LANGS = ['it','en']
GA = '''<script async src="https://www.googletagmanager.com/gtag/js?id=G-F2ME1WYQHG"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','G-F2ME1WYQHG',{anonymize_ip:true,allow_google_signals:false,allow_ad_personalization_signals:false});</script>'''
FLAGFIX = '''<script>(function(){if(!/Windows/i.test(navigator.userAgent))return;var st=document.createElement('style');st.textContent="@font-face{font-family:'TwFlags';src:url('https://cdn.jsdelivr.net/npm/country-flag-emoji-polyfill@0.1/dist/TwemojiCountryFlags.woff2') format('woff2');unicode-range:U+1F1E6-1F1FF,U+1F3F4,U+E0062-E007F;font-display:swap}body{font-family:'TwFlags','Segoe UI',system-ui,sans-serif}";document.head.appendChild(st);})();</script>'''

DEF={'RB','LB','CB'};MID={'CDM','CM','CAM','AM','LM','RM'};FWD={'LW','RW','SS','CF','ST'}
POS_ORDER=['GK','RB','CB','LB','CDM','CM','RM','LM','CAM','AM','RW','LW','SS','CF','ST']

T={
'it':{
 'roles':{'GK':'Portiere','RB':'Terzino destro','LB':'Terzino sinistro','CB':'Difensore centrale','CDM':'Mediano','CM':'Centrocampista','CAM':'Trequartista','AM':'Trequartista','LM':'Esterno sinistro','RM':'Esterno destro','LW':'Ala sinistra','RW':'Ala destra','SS':'Seconda punta','CF':'Centravanti','ST':'Centravanti'},
 'tourn':{'ucl':('UCL Legends','Champions League','ucl.html','🏆'),'copa':('Copa Libertadores','Copa Libertadores','copa.html','🌎'),'wc':('World Cup Legends','Mondiali','worldcup.html','🌍')},
 'title':'Rosa %s %s — Giocatori e Rating | Decempionz',
 'h1':'Rosa %s %s',
 'desc':'La rosa %s %s in %s: %d giocatori con ruolo e rating. I migliori: %s. Draftala su Decempionz.',
 'crumb_home':'Home','crumb_hub':'Rose storiche',
 'stat_players':'Giocatori','stat_avg':'Rating medio','stat_leg':'Leggende ★10','stat_dmf':'Dif-Cen-Att',
 'h2_squad':'La rosa completa','h2_rel':'Rose correlate',
 'th':('Giocatore','Ruolo','Rating'),
 'cta':'⚽ Drafta le leggende su Decempionz — gratis, senza registrazione',
 'foot_play':'Gioca','foot_all':'Tutte le rose','foot_about':'About',
 'idx_title':'%d Rose Storiche del Calcio: Giocatori e Rating | Decempionz',
 'idx_desc':"L'archivio completo delle %d rose storiche giocabili su Decempionz: Champions League, Copa Libertadores e Mondiali. Ogni rosa con giocatori, ruoli e rating.",
 'idx_h1':'Tutte le %d rose storiche di Decempionz',
 'idx_intro':"Ogni squadra ha la sua pagina con la rosa completa: giocatori, ruoli e rating usati nel draft. Dalle pioniere degli anni '50 alle campionesse di oggi, su tre competizioni.",
 'idx_sec':'%s %s (%d rose)',
 'idx_cta':'⚽ Gioca ora — gratis, senza registrazione',
 'intros':[
  "La rosa del {name} {season} è una delle {ntot} squadre storiche giocabili in {tourn} su Decempionz. {nles} dei suoi {np} giocatori hanno rating leggenda, con una media squadra di {avg}. Appartiene all'era {era}.",
  "Che squadra era il {name} nella stagione {season}? Questa pagina raccoglie la rosa completa usata in {tourn} su Decempionz: {np} giocatori dell'era {era}, rating medio {avg}, con {top} tra i nomi di punta.",
  "Il {name} {season} fa parte dell'era {era} di {tourn} su Decempionz. Qui trovi tutti i {np} giocatori con ruolo e rating: il valore medio della rosa è {avg} e i profili più forti sono {top}.",
  "{np} giocatori, rating medio {avg}, era {era}: il {name} {season} è una delle rose storiche di {tourn} che puoi draftare su Decempionz. In evidenza {top}.",
 ],
 'dec':',','dir':'rose','tpage':lambda f:'/'+f,
},
'en':{
 'roles':{'GK':'Goalkeeper','RB':'Right-back','LB':'Left-back','CB':'Centre-back','CDM':'Defensive midfielder','CM':'Midfielder','CAM':'Attacking midfielder','AM':'Attacking midfielder','LM':'Left midfielder','RM':'Right midfielder','LW':'Left winger','RW':'Right winger','SS':'Second striker','CF':'Centre forward','ST':'Striker'},
 'tourn':{'ucl':('UCL Legends','Champions League','en/ucl.html','🏆'),'copa':('Copa Libertadores','Copa Libertadores','en/copa.html','🌎'),'wc':('World Cup Legends','World Cup','en/worldcup.html','🌍')},
 'title':'%s %s Squad — Players & Ratings | Decempionz',
 'h1':'%s %s Squad',
 'desc':'The %s %s squad in the %s: %d players with roles and ratings. Top players: %s. Draft it on Decempionz.',
 'crumb_home':'Home','crumb_hub':'Historic squads',
 'stat_players':'Players','stat_avg':'Avg rating','stat_leg':'★10 legends','stat_dmf':'Def-Mid-Fwd',
 'h2_squad':'The full squad','h2_rel':'Related squads',
 'th':('Player','Role','Rating'),
 'cta':'⚽ Draft the legends on Decempionz — free, no signup',
 'foot_play':'Play','foot_all':'All squads','foot_about':'About',
 'idx_title':'%d Historic Football Squads: Players & Ratings | Decempionz',
 'idx_desc':'The full archive of the %d historic squads playable on Decempionz: Champions League, Copa Libertadores and World Cup. Every squad with players, roles and ratings.',
 'idx_h1':'All %d historic squads of Decempionz',
 'idx_intro':"Every team has its own page with the full squad: players, roles and the ratings used in the draft. From the pioneers of the '50s to today's champions, across three competitions.",
 'idx_sec':'%s %s (%d squads)',
 'idx_cta':'⚽ Play now — free, no signup',
 'intros':[
  "The {name} {season} squad is one of the {ntot} historic teams playable in the {tourn} on Decempionz. {nles} of its {np} players hold a legend rating, with a squad average of {avg}. It belongs to the {era} era.",
  "How good were {name} in the {season} season? This page collects the full squad used in the {tourn} on Decempionz: {np} players from the {era} era, average rating {avg}, with {top} among the standout names.",
  "{name} {season} belongs to the {era} era of the {tourn} on Decempionz. Here you find all {np} players with role and rating: the squad average is {avg} and the strongest profiles are {top}.",
  "{np} players, average rating {avg}, {era} era: {name} {season} is one of the historic squads you can draft on Decempionz. Standouts: {top}.",
 ],
 'dec':'.','dir':'en/rose','tpage':lambda f:'/'+f,
},
}

def slugify(s):
    s=unicodedata.normalize('NFKD',s).encode('ascii','ignore').decode('ascii')
    s=re.sub(r'[^a-zA-Z0-9]+','-',s).strip('-').lower()
    return s

def natflag(code):
    if not code:return ''
    sp={'EN':'🏴󠁧󠁢󠁥󠁮󠁧󠁿','SC':'🏴󠁧󠁢󠁳󠁣󠁴󠁿','WLS':'🏴󠁧󠁢󠁷󠁬󠁳󠁿','NIR':'🇬🇧'}
    if code in sp:return sp[code]
    if len(code)==2:return chr(0x1F1E6+ord(code[0])-65)+chr(0x1F1E6+ord(code[1])-65)
    return code

def esc(s):return s.replace('&','&amp;').replace('<','&lt;').replace('>','&gt;')

def extract_data():
    h=open(os.path.join(BASE,'index.html'),encoding='utf-8').read()
    gd=os.path.join(BASE,'game-data.js')
    if os.path.exists(gd):
        h=open(gd,encoding='utf-8').read()+'\n'+h
    def grab(name):
        m=re.search(r'const %s\s*=\s*'%name,h);i=m.end()
        oc=h[i];cc='}' if oc=='{' else ']';depth=0;j=i
        while True:
            c=h[j]
            if c==oc:depth+=1
            elif c==cc:
                depth-=1
                if depth==0:break
            j+=1
        return h[i:j+1]
    parts=['const %s=%s;'%(v,grab(v)) for v in
        ['TEAMS','COPA_TEAMS','WC_TEAMS','TOURNAMENTS','COPA_TOURNAMENTS','WC_TOURNAMENTS']]
    out=os.path.join(tempfile.gettempdir(),'dcz_teams.json')
    code='\n'.join(parts)+"\nrequire('fs').writeFileSync(%s,JSON.stringify({TEAMS,COPA_TEAMS,WC_TEAMS,TOURNAMENTS,COPA_TOURNAMENTS,WC_TOURNAMENTS}));"%json.dumps(out)
    js=os.path.join(tempfile.gettempdir(),'dcz_extract.js')
    open(js,'w',encoding='utf-8').write(code)
    subprocess.run(['node',js],check=True)
    return json.load(open(out,encoding='utf-8'))

CSS='''*{box-sizing:border-box;margin:0;padding:0}
body{background:#07090f;color:#e8edf5;font-family:'Segoe UI',system-ui,sans-serif;line-height:1.6}
a{color:#e8c04a;text-decoration:none}a:hover{text-decoration:underline}
.wrap{max-width:760px;margin:0 auto;padding:24px 16px 60px}
.logo{font-weight:900;letter-spacing:3px;text-transform:uppercase;font-size:1rem;background:linear-gradient(135deg,#2451a8,#c9a227 55%,#e8c04a);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;display:inline-block}
.crumb{font-size:.72rem;color:#6b7e95;margin:14px 0 6px}.crumb a{color:#6b7e95}
.langsw{float:right;font-size:.7rem;margin-top:4px}.langsw a{margin-left:8px;color:#6b7e95}.langsw a.on{color:#e8c04a;font-weight:700}
h1{font-size:1.55rem;line-height:1.25;margin:4px 0 8px}
h2{font-size:1.05rem;margin:28px 0 10px;color:#e8c04a}
.chips{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 18px}
.chip{font-size:.68rem;font-weight:700;padding:4px 12px;border-radius:20px;background:#111928;border:1px solid #1e2d42;color:#93c5fd}
.intro{font-size:.88rem;color:#b8c4d4;margin-bottom:20px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:10px;margin:16px 0 6px}
.stat{background:#111928;border:1px solid #1e2d42;border-radius:12px;padding:12px;text-align:center}
.stat b{display:block;font-size:1.3rem;color:#e8c04a}.stat span{font-size:.6rem;color:#6b7e95;text-transform:uppercase;letter-spacing:1px}
table{width:100%;border-collapse:collapse;font-size:.84rem;margin:8px 0}
th{font-size:.62rem;text-transform:uppercase;letter-spacing:1px;color:#6b7e95;text-align:left;padding:7px 8px;border-bottom:1px solid #1e2d42}
td{padding:7px 8px;border-bottom:1px solid #131c2c}
tr:hover td{background:#0d1522}
.r10{color:#e8c04a;font-weight:800}.r9{color:#93c5fd;font-weight:700}
.rel{display:flex;gap:8px;flex-wrap:wrap}
.rel a{font-size:.74rem;background:#111928;border:1px solid #1e2d42;border-radius:8px;padding:7px 12px}
.cta{display:block;text-align:center;margin:30px 0 10px;padding:14px;background:linear-gradient(135deg,#c9a227,#e8c04a);color:#07090f!important;font-weight:800;border-radius:12px;font-size:.95rem}
.cta:hover{text-decoration:none;opacity:.9}
footer{margin-top:36px;padding-top:14px;border-top:1px solid #1e2d42;font-size:.68rem;color:#6b7e95;text-align:center}
footer a{color:#6b7e95;margin:0 6px}'''

def hreflang_pair(slug):
    if slug is None:
        it=SITE+'/rose/';en=SITE+'/en/rose/'
    else:
        it='%s/rose/%s.html'%(SITE,slug);en='%s/en/rose/%s.html'%(SITE,slug)
    return ('<link rel="alternate" hreflang="it" href="%s">\n<link rel="alternate" hreflang="en" href="%s">\n<link rel="alternate" hreflang="x-default" href="%s">'%(it,en,it))

def langsw(slug,lang):
    if slug is None:
        it='/rose/';en='/en/rose/'
    else:
        it='/rose/%s.html'%slug;en='/en/rose/%s.html'%slug
    return '<span class="langsw"><a href="%s"%s>IT</a><a href="%s"%s>EN</a></span>'%(
        it,' class="on"' if lang=='it' else '',en,' class="on"' if lang=='en' else '')

def fmt_avg(avg,L):return ('%.1f'%avg).replace('.',L['dec'])

def build_page(tid,t,mode,era,all_by_club,slug_of,d,lang):
    L=T[lang]
    tourn_name,tourn_label,tourn_page,temoji=L['tourn'][mode]
    name,season=t['name'],t['season']
    players=t['players']
    np_=len(players)
    avg=sum(p['r'] for p in players)/np_
    n10=sum(1 for p in players if p['r']>=10)
    ndef=sum(1 for p in players if p['p'] in DEF)
    nmid=sum(1 for p in players if p['p'] in MID)
    nfwd=sum(1 for p in players if p['p'] in FWD)
    top=sorted(players,key=lambda p:-p['r'])[:3]
    topnames=', '.join(p['n'] for p in top)
    slug=slug_of[(mode,tid)]
    url='%s/%s/%s.html'%(SITE,L['dir'],slug)
    h1=L['h1']%(name,season)
    title=L['title']%(name,season)
    desc=L['desc']%(name,season,tourn_label,np_,topnames)
    if len(desc)>160:desc=desc.rsplit('. ',1)[0]+'.'  # snippet Google: ~160 caratteri, si rinuncia alla call to action
    ivar=L['intros'][int(hashlib.md5(slug.encode()).hexdigest(),16)%len(L['intros'])]
    ntot=len({'ucl':d['TEAMS'],'copa':d['COPA_TEAMS'],'wc':d['WC_TEAMS']}[mode])
    intro=ivar.format(name=esc(name),season=season,tourn=tourn_label,np=np_,avg=fmt_avg(avg,L),
        era=esc(era['name']) if era else 'All Time',top=esc(topnames),nles=n10,ntot=ntot)
    rows=[]
    for p in sorted(players,key=lambda p:(POS_ORDER.index(p['p']) if p['p'] in POS_ORDER else 99,-p['r'])):
        cls=' class="r10"' if p['r']>=10 else (' class="r9"' if p['r']==9 else '')
        flag=natflag(p.get('nat',''))
        rows.append('<tr><td%s>%s %s</td><td>%s</td><td%s>%d ★</td></tr>'%(cls,flag,esc(p['n']),L['roles'].get(p['p'],p['p']),cls,p['r']))
    rel=[]
    for oid in all_by_club.get((mode,t.get('club')),[]):
        if oid!=tid: rel.append(oid)
    if era:
        for oid in era['teams']:
            if oid!=tid and (mode,oid) in slug_of and oid not in rel: rel.append(oid)
    rel=rel[:6]
    teams_mode={'ucl':d['TEAMS'],'copa':d['COPA_TEAMS'],'wc':d['WC_TEAMS']}[mode]
    relhtml=''.join('<a href="%s.html">%s %s</a>'%(slug_of[(mode,o)],esc(teams_mode[o]['name']),teams_mode[o]['season']) for o in rel if o in teams_mode)
    erachip=('<span class="chip">%s %s · %s</span>'%(era.get('emoji',''),esc(era['name']),era.get('years',''))) if era else ''
    ld=json.dumps({"@context":"https://schema.org","@type":"SportsTeam","name":'%s %s'%(name,season),"sport":"Soccer",
      "url":url,"memberOf":{"@type":"SportsOrganization","name":tourn_label},
      "athlete":[{"@type":"Person","name":p['n']} for p in top]},ensure_ascii=False)
    bc=json.dumps({"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":[
      {"@type":"ListItem","position":1,"name":L['crumb_home'],"item":SITE+'/'},
      {"@type":"ListItem","position":2,"name":L['crumb_hub'],"item":SITE+'/'+('rose/' if lang=='it' else 'en/rose/')},
      {"@type":"ListItem","position":3,"name":h1,"item":url}]},ensure_ascii=False)
    hub='/rose/' if lang=='it' else '/en/rose/'
    return '''<!DOCTYPE html>
<html lang="%s">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>%s</title>
<meta name="description" content="%s">
<link rel="canonical" href="%s">
%s
<meta property="og:title" content="%s">
<meta property="og:description" content="%s">
<meta property="og:type" content="article">
<meta property="og:url" content="%s">
<meta property="og:image" content="%s/og-image.png">
<meta name="twitter:card" content="summary_large_image">
<link rel="apple-touch-icon" href="/icon-192.png">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⚽</text></svg>">
<script type="application/ld+json">%s</script>
<script type="application/ld+json">%s</script>
%s
%s
<style>%s</style>
</head>
<body>
<div class="wrap">
%s<a href="/" class="logo">Decempionz</a>
<div class="crumb"><a href="/">%s</a> › <a href="%s">%s</a> › %s %s</div>
<h1>%s</h1>
<div class="chips"><span class="chip">%s %s</span>%s<span class="chip">%s</span></div>
<p class="intro">%s</p>
<div class="stats">
<div class="stat"><b>%d</b><span>%s</span></div>
<div class="stat"><b>%s</b><span>%s</span></div>
<div class="stat"><b>%d</b><span>%s</span></div>
<div class="stat"><b>%d-%d-%d</b><span>%s</span></div>
</div>
<h2>%s</h2>
<table>
<thead><tr><th>%s</th><th>%s</th><th>%s</th></tr></thead>
<tbody>
%s
</tbody>
</table>
<h2>%s</h2>
<div class="rel">%s</div>
<a class="cta" href="/">%s</a>
<footer>© 2026 Decempionz · <a href="/">%s</a><a href="%s">%s</a><a href="%s">%s</a><a href="%s">%s</a></footer>
</div>
</body>
</html>'''%(lang,esc(title),esc(desc),url,hreflang_pair(slug),esc(title),esc(desc),url,SITE,ld,bc,GA,FLAGFIX,CSS,
    langsw(slug,lang),L['crumb_home'],hub,L['crumb_hub'],esc(name),season,esc(h1),temoji,tourn_name,erachip,season,intro,
    np_,L['stat_players'],fmt_avg(avg,L),L['stat_avg'],n10,L['stat_leg'],ndef,nmid,nfwd,L['stat_dmf'],
    L['h2_squad'],L['th'][0],L['th'][1],L['th'][2],'\n'.join(rows),L['h2_rel'],relhtml,L['cta'],
    L['foot_play'],hub,L['foot_all'],L['tpage'](tourn_page),tourn_label,
    '/about.html' if lang=='it' else '/en/about.html',L['foot_about'])

def build_index(sets,slug_of,lang,n):
    L=T[lang]
    hub_url=SITE+('/rose/' if lang=='it' else '/en/rose/')
    sec=[]
    for mode,teams in sets:
        tourn_name,tourn_label,tourn_page,temoji=L['tourn'][mode]
        links=''.join('<a href="%s.html">%s %s</a>'%(slug_of[(mode,tid)],esc(t['name']),t['season'])
            for tid,t in sorted(teams.items(),key=lambda kv:(kv[1]['name'],kv[1]['season'])))
        sec.append('<h2>%s</h2><div class="rel">%s</div>'%(L['idx_sec']%(temoji,tourn_name,len(teams)),links))
    title=L['idx_title']%n;desc=L['idx_desc']%n
    graph=json.dumps({"@context":"https://schema.org","@graph":[
      {"@type":"CollectionPage","name":title,"description":desc,"url":hub_url,
       "breadcrumb":{"@type":"BreadcrumbList","itemListElement":[
         {"@type":"ListItem","position":1,"name":L['crumb_home'],"item":SITE+'/'},
         {"@type":"ListItem","position":2,"name":L['crumb_hub'],"item":hub_url}]}},
      {"@type":"ItemList","name":title,"url":hub_url,"numberOfItems":n}
    ]},ensure_ascii=False)
    return '''<!DOCTYPE html>
<html lang="%s">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>%s</title>
<meta name="description" content="%s">
<link rel="canonical" href="%s">
%s
<meta property="og:title" content="%s">
<meta property="og:description" content="%s">
<meta property="og:type" content="website">
<meta property="og:url" content="%s">
<meta property="og:image" content="%s/og-image.png">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⚽</text></svg>">
<script type="application/ld+json">%s</script>
%s
<style>%s</style>
</head>
<body>
<div class="wrap">
%s<a href="/" class="logo">Decempionz</a>
<div class="crumb"><a href="/">%s</a> › %s</div>
<h1>%s</h1>
<p class="intro">%s</p>
%s
<a class="cta" href="/">%s</a>
<footer>© 2026 Decempionz · <a href="/">%s</a><a href="%s">UCL</a><a href="%s">Copa</a><a href="%s">World Cup</a><a href="%s">%s</a></footer>
</div>
</body>
</html>'''%(lang,esc(title),esc(desc),hub_url,hreflang_pair(None),esc(title),esc(desc),hub_url,SITE,graph,GA,CSS,
    langsw(None,lang),L['crumb_home'],L['crumb_hub'],L['idx_h1']%n,L['idx_intro'],'\n'.join(sec),L['idx_cta'],
    L['foot_play'],L['tpage'](L['tourn']['ucl'][2]),L['tpage'](L['tourn']['copa'][2]),L['tpage'](L['tourn']['wc'][2]),
    '/about.html' if lang=='it' else '/en/about.html',L['foot_about'])

def main():
    d=extract_data()
    era_of={}
    for lst,md in [('TOURNAMENTS','ucl'),('COPA_TOURNAMENTS','copa'),('WC_TOURNAMENTS','wc')]:
        for e in d[lst]:
            for tid in e.get('teams',[]):era_of.setdefault((md,tid),e)
    slug_of={};all_by_club={}
    sets=[('ucl',d['TEAMS']),('copa',d['COPA_TEAMS']),('wc',d['WC_TEAMS'])]
    for mode,teams in sets:
        for tid,t in teams.items():
            slug=slugify('%s %s'%(t['name'],t['season']))
            if slug in slug_of.values():slug=slugify('%s %s %s'%(t['name'],mode,t['season']))
            assert slug not in slug_of.values(),'slug duplicato: '+slug
            slug_of[(mode,tid)]=slug
            all_by_club.setdefault((mode,t.get('club')),[]).append(tid)
    n=0
    for lang in LANGS:
        outdir=os.path.join(BASE,*T[lang]['dir'].split('/'))
        os.makedirs(outdir,exist_ok=True)
        cnt=0
        for mode,teams in sets:
            for tid,t in teams.items():
                html=build_page(tid,t,mode,era_of.get((mode,tid)),all_by_club,slug_of,d,lang)
                open(os.path.join(outdir,slug_of[(mode,tid)]+'.html'),'w',encoding='utf-8').write(html)
                cnt+=1
        open(os.path.join(outdir,'index.html'),'w',encoding='utf-8').write(build_index(sets,slug_of,lang,cnt))
        print('%s: %d pagine + indice'%(T[lang]['dir'],cnt))
        n=cnt
    fixed=[('','1.0','weekly'),('about.html','0.6','monthly'),('ucl.html','0.8','monthly'),
      ('copa.html','0.8','monthly'),('worldcup.html','0.8','monthly'),
      ('sfide.html','0.7','weekly'),('rose/','0.7','weekly'),
      ('en/ucl.html','0.8','monthly'),('en/copa.html','0.8','monthly'),('en/worldcup.html','0.8','monthly'),
      ('en/about.html','0.6','monthly'),('es/ucl.html','0.8','monthly'),('es/copa.html','0.8','monthly'),
      ('es/worldcup.html','0.8','monthly'),('es/about.html','0.6','monthly'),('en/rose/','0.7','weekly')]
    urls=['  <url>\n    <loc>%s/%s</loc>\n    <lastmod>%s</lastmod>\n    <changefreq>%s</changefreq>\n    <priority>%s</priority>\n  </url>'%(SITE,p,lastmod(p),cf,pr) for p,pr,cf in fixed]
    for key,slug in sorted(slug_of.items(),key=lambda kv:kv[1]):
        for pre in ['rose','en/rose']:
            urls.append('  <url>\n    <loc>%s/%s/%s.html</loc>\n    <lastmod>%s</lastmod>\n    <changefreq>monthly</changefreq>\n    <priority>0.5</priority>\n  </url>'%(SITE,pre,slug,lastmod('%s/%s.html'%(pre,slug))))
    sm='<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n%s\n</urlset>\n'%'\n'.join(urls)
    open(os.path.join(BASE,'sitemap.xml'),'w',encoding='utf-8').write(sm)
    print('sitemap: %d url'%len(urls))

if __name__=='__main__':
    main()
