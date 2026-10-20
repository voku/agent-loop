# Bundled agent-dotfiles profile

These files are copied from `voku/agent-dotfiles` at commit
`3f8a11908aee070e4fb3a5619118188afdffd528` (2026-10-13). They are included in
the CLI image so Docker users get the reviewed profile without a host checkout
or a network fetch during image build.

Included paths:

- `.bashrc`, `.shellrc`
- `.exports`, `.functions`
- `.redpill/config/agent.env`, `.redpill/config/ripgreprc`
- `.redpill/lib/activation.sh`, `.redpill/lib/deactivation.sh`
- `LICENSE`

The image activates `.bashrc` through its `BASH_ENV` bootstrap. Refresh this
snapshot only from a reviewed upstream commit, then update this file and verify
the shell activation contract inside the built image.
