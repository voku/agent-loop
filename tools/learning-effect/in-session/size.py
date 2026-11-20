import subprocess,sys,re,json
def classify(path):
    if path.startswith('tests/') or '/tests/' in path: return 'test'
    if path in ('CHANGELOG.md',) or path.startswith('docs/') or path.startswith('.agent-loop/'): return 'skip'
    return 'src'
def numstat_ws(repo):
    out=subprocess.run(['git','-C',repo,'diff','--numstat','HEAD'],capture_output=True,text=True).stdout
    rows=[l.split('\t') for l in out.splitlines()]
    for f in subprocess.run(['git','-C',repo,'ls-files','-z','--others','--exclude-standard'],capture_output=True,text=True).stdout.split('\0'):
        if not f: continue
        if f.startswith('.agent-loop/'): continue
        n=sum(1 for _ in open(f'{repo}/{f}',errors='ignore')); rows.append([str(n),'0',f])
    return rows
def numstat_commit(c,only=None):
    out=subprocess.run(['git','-C','/home/user/agent-loop','show','--numstat','--format=',c],capture_output=True,text=True).stdout
    rows=[l.split('\t') for l in out.splitlines() if l.strip()]
    return [r for r in rows if only is None or r[2] in only]
def summ(rows):
    s={'src_add':0,'src_del':0,'test_add':0,'files':0,'new_files':0}
    for a,d,p in rows:
        k=classify(p)
        if k=='skip' or a=='-': continue
        s['files']+=1
        if k=='src': s['src_add']+=int(a); s['src_del']+=int(d)
        else: s['test_add']+=int(a)
    return s
