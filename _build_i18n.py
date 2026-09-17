# -*- coding: utf-8 -*-
"""
_build_i18n.py — genera le versioni EN e ES delle pagine contenuto tri-lingua.
Fonte di verità: le pagine IT (ucl/copa/worldcup/about.html) con i 3 blocchi data-lang.
Output: en/<pagina>.html e es/<pagina>.html. Alle pagine IT aggiunge (idempotente) gli hreflang.
Uso: python _build_i18n.py  — rilanciare dopo ogni modifica alle pagine contenuto.
"""
import re, os

BASE=os.path.dirname(os.path.abspath(__file__))
SITE='https://decempionz.com'
PAGES=['ucl','copa','worldcup','about']
LANGS=['en','es']

META={
 ('ucl','en'):("UCL Legends: 83 Historic Champions League Squads | Decempionz",
   "Discover the history of the UEFA Champions League: format, trivia, historic winners and the 83 legendary squads you can draft for free in Decempionz."),
 ('ucl','es'):("UCL Legends: 83 Plantillas Históricas de la Champions League | Decempionz",
   "Descubre la historia de la Champions League: formato, curiosidades, ganadores históricos y las 83 plantillas legendarias que puedes draftear gratis en Decempionz."),
 ('copa','en'):("Copa Libertadores: 51 Historic South American Squads | Decempionz",
   "The history of the Copa Libertadores: format, trivia and the 51 historic squads from Peñarol 1960-61 to Botafogo 2024, all playable for free in Decempionz."),
 ('copa','es'):("Copa Libertadores: 51 Plantillas Históricas del Fútbol Sudamericano | Decempionz",
   "La historia de la Copa Libertadores: formato, curiosidades y las 51 plantillas históricas de Peñarol 1960-61 a Botafogo 2024, jugables gratis en Decempionz."),
 ('worldcup','en'):("World Cup Legends: 87 Historic National Teams | Decempionz",
   "The history of the FIFA World Cup: 87 historic national squads from Uruguay 1930 to Argentina 2022. Draft your dream XI and win the cup in Decempionz."),
 ('worldcup','es'):("World Cup Legends: 87 Selecciones Históricas de los Mundiales | Decempionz",
   "La historia de los Mundiales: 87 selecciones históricas de Uruguay 1930 a Argentina 2022. Draftea tu once ideal y gana la copa en Decempionz."),
 ('about','en'):("The Decempionz Project | Free Historical Football Draft Game",
   "Decempionz is a free fan project: a historical football draft game inspired by the Champions League anthem. Build your XI with UCL, Copa Libertadores and World Cup legends. No paywall, no signup."),
 ('about','es'):("El Proyecto Decempionz | Juego de Draft de Fútbol Histórico Gratis",
   "Decempionz es un fan project gratuito de draft de fútbol histórico inspirado en el himno de la Champions. Construye tu once con leyendas de la UCL, Copa Libertadores y Mundiales. Sin pagos, sin registro."),
}

def balanced_div(h,start):
    """start = indice di '<div ...>'; ritorna indice dopo il </div> corrispondente."""
    i=h.find('>',start)+1
    depth=1
    while depth>0:
        no=h.find('<div',i);nc=h.find('</div>',i)
        if nc==-1:raise ValueError('div non chiuso')
        if no!=-1 and no<nc:depth+=1;i=h.find('>',no)+1
        else:depth-=1;i=nc+6
    return i

def hreflang_block(page,langs):
    u=SITE+'/'+page+'.html'
    lines=['<link rel="alternate" hreflang="it" href="%s">'%u]
    for L in langs:
        lines.append('<link rel="alternate" hreflang="%s" href="%s/%s/%s.html">'%(L,SITE,L,page))
    lines.append('<link rel="alternate" hreflang="x-default" href="%s">'%u)
    return '\n'.join(lines)

def switcher(page,active,langs):
    def a(lang,href,label):
        cls='lang-btn active' if lang==active else 'lang-btn'
        return '<a class="%s" href="%s" style="text-decoration:none;display:inline-block">%s</a>'%(cls,href,label)
    rows=[a('it','/%s.html'%page,'🇮🇹 Italiano')]
    if 'en' in langs:rows.append(a('en','/en/%s.html'%page,'🇬🇧 English'))
    if 'es' in langs:rows.append(a('es','/es/%s.html'%page,'🇪🇸 Español'))
    return '  <div class="lang-bar">\n    '+'\n    '.join(rows)+'\n  </div>'

def build_lang_page(page,lang,h):
    title,desc=META[(page,lang)]
    url='%s/%s/%s.html'%(SITE,lang,page)
    blocks={}
    for m in re.finditer(r'<div data-lang="(\w+)"[^>]*>',h):
        L=m.group(1)
        blocks[L]=(m.start(),balanced_div(h,m.start()))
    assert 'it' in blocks and lang in blocks,'blocco %s mancante in %s'%(lang,page)
    page_langs=[L for L in ('en','es') if L in blocks]
    first=min(v[0] for v in blocks.values());last=max(v[1] for v in blocks.values())
    prefix=h[:first];target=h[blocks[lang][0]:blocks[lang][1]];suffix=h[last:]
    target=re.sub(r'^<div data-lang="\w+"[^>]*>','<div data-lang="%s" class="visible">'%lang,target,count=1)
    out=prefix+target+suffix
    out=out.replace('<html lang="it">','<html lang="%s">'%lang,1)
    out=re.sub(r'<title>[^<]*</title>','<title>%s</title>'%title,out,count=1)
    out=re.sub(r'(<meta name="description" content=")[^"]*(">)',lambda m:m.group(1)+desc+m.group(2),out,count=1)
    out=re.sub(r'(<meta property="og:title" content=")[^"]*(">)',lambda m:m.group(1)+title+m.group(2),out,count=1)
    out=re.sub(r'(<meta property="og:description" content=")[^"]*(">)',lambda m:m.group(1)+desc+m.group(2),out,count=1)
    out=re.sub(r'<meta property="og:url" content="[^"]*">','<meta property="og:url" content="%s">'%url,out,count=1)
    out=re.sub(r'[ \t]*<link rel="alternate" hreflang="[^"]*" href="[^"]*">\n?','',out)
    out=re.sub(r'<link rel="canonical" href="[^"]*">','<link rel="canonical" href="%s">\n%s'%(url,hreflang_block(page,page_langs)),out,count=1)
    out=out.replace('"url": "%s/%s.html"'%(SITE,page),'"url": "%s"'%url)
    out=out.replace('"inLanguage": ["it", "en", "es"]','"inLanguage": "%s"'%lang)
    out=re.sub(r'  <div class="lang-bar">.*?</div>',switcher(page,lang,page_langs),out,count=1,flags=re.S)
    i=out.find('function setLang(lang){')
    if i!=-1:
        j=out.find('})();',i)
        if j!=-1:out=out[:i]+out[j+5:]
    for pg in PAGES:
        out=re.sub(r'href="/?%s\.html"'%pg,'href="/%s/%s.html"'%(lang,pg),out)
    out=out.replace('<link rel="alternate" hreflang="it" href="%s/%s/'%(SITE,lang),'<link rel="alternate" hreflang="it" href="%s/'%SITE)
    return out

def add_hreflang_it(page,langs):
    p=os.path.join(BASE,page+'.html')
    h=open(p,encoding='utf-8').read()
    if 'hreflang' in h:return False
    can='<link rel="canonical" href="%s/%s.html">'%(SITE,page)
    assert can in h,'canonical non trovato in '+page
    h=h.replace(can,can+'\n'+hreflang_block(page,langs),1)
    open(p,'w',encoding='utf-8').write(h)
    return True

def main():
    for lang in LANGS:
        os.makedirs(os.path.join(BASE,lang),exist_ok=True)
    for page in PAGES:
        src=open(os.path.join(BASE,page+'.html'),encoding='utf-8').read()
        avail=[L for L in LANGS if 'data-lang="%s"'%L in src]
        for lang in avail:
            out=build_lang_page(page,lang,src)
            fp=os.path.join(BASE,lang,page+'.html')
            open(fp,'w',encoding='utf-8').write(out)
            print('%s/%s.html: %d bytes (da %d)'%(lang,page,len(out),len(src)))
        if add_hreflang_it(page,avail):print(page+'.html: hreflang aggiunti (%s)'%'+'.join(avail))
        else:print(page+'.html: hreflang già presenti')

if __name__=='__main__':
    main()
