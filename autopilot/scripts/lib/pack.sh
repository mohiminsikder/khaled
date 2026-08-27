#!/usr/bin/env bash
# pack.sh — build a context pack for one phase.
#
# Two turns of grep replace four or five turns of model-driven search. It is
# faster because grep is faster than inference, and cheaper because those turns
# are never billed. The pack is a map, not a substitute for reading: the model
# still opens what it needs, but it starts on the right page.

build_pack() {
  local p="$1" out="$AP/pack/$1.md" f n
  mkdir -p "$AP/pack"
  {
    echo "# Context pack — $p"
    echo "$(phase_field "$p" done_when)"
    echo
    for f in $(phase_files "$p"); do
      if [[ ! -f "$f" ]]; then
        echo "## $f — does not exist yet"
        echo
        continue
      fi
      n=$(wc -l < "$f" | xargs)
      echo "## $f ($n lines)"
      if [[ "$n" -le 120 ]]; then
        echo '```'
        cat "$f"
        echo '```'
      else
        # Signatures and structure only. The model reads the spans it needs.
        echo "Structure (grep for a symbol, then read the span):"
        echo '```'
        grep -nE '^\s*(export |public |private |protected |async |static )?(function|class|interface|type|const|def|func|fn|struct|impl|module|namespace)\b' "$f" \
          | head -60 || true
        echo '```'
      fi
      echo
    done

    # Tests that name the same files — usually where verify's failure will land.
    local tests
    tests=$(grep -rlE "$(phase_files "$p" | tr ' ' '|' | sed 's/|$//')" \
      test tests spec __tests__ 2>/dev/null | head -5 || true)
    if [[ -n "$tests" ]]; then
      echo "## Related tests"
      printf '%s\n' "$tests"
      echo
    fi

    # What this change can reach, if a code graph is available. Grep cannot
    # answer this cheaply; the model would otherwise find out by searching.
    graph_pack_section "$p" 2>/dev/null || true

    echo "## Verify"
    echo '`'"$(phase_field "$p" verify)"'`'
  } > "$out"

  # A pack that grows past ~1200 lines is a phase that is too big. Truncate
  # rather than quietly paying for it, and say so.
  if [[ $(wc -l < "$out") -gt 1200 ]]; then
    head -1200 "$out" > "$out.tmp" && mv "$out.tmp" "$out"
    echo "" >> "$out"
    echo "(pack truncated — this phase names too much surface; consider splitting it)" >> "$out"
  fi
  printf '%s' "$out"
}
