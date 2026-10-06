import re,sys
def strip_dead(p):
    removed=[]
    while True:
        s=open(p).read()
        methods=re.findall(r'private (?:static )?function (\w+)\(',s)
        dead=[m for m in methods if len(re.findall(r'(?:->|::)'+m+r'\(',s))==0 and ("'"+m+"'") not in s]
        consts=[c for c in re.findall(r'private const (\w+)',s) if ('self::'+c) not in s and ('static::'+c) not in s]
        if not dead and not consts: break
        for m in dead:
            mm=re.search(r'\n((?:    /\*\*(?:(?!\*/).)*?\*/\n|    //[^\n]*\n)*)    private (?:static )?function '+m+r'\(',s,flags=re.S)
            st=mm.start()+1
            i=s.index('{',mm.end()); depth=0; j=i
            while True:
                c=s[j]
                if c=='{':depth+=1
                elif c=='}':
                    depth-=1
                    if depth==0: break
                j+=1
            en=j+1
            if s[en]=='\n': en+=1
            s=s[:st]+s[en:]; removed.append(m)
        for c in consts:
            mm=re.search(r'\n((?:    /\*\*(?:(?!\*/).)*?\*/\n|    //[^\n]*\n)*)    private const '+c+r'\b',s,flags=re.S)
            st=mm.start()+1
            j=s.index('=',mm.end()); depth=0
            while True:
                ch=s[j]
                if ch in '[(':depth+=1
                elif ch in '])':depth-=1
                elif ch==';' and depth==0: break
                j+=1
            en=j+1
            if s[en]=='\n': en+=1
            s=s[:st]+s[en:]; removed.append(c)
        open(p,'w').write(s)
    return removed
for p in sys.argv[1:]:
    print(p, strip_dead(p))
