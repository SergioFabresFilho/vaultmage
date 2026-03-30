import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  Dimensions,
  FlatList,
  Image,
  Modal,
  Platform,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  TouchableOpacity,
  View,
} from 'react-native';
import { useRouter } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { SvgUri } from 'react-native-svg';
import { useAuth } from '@/context/AuthContext';

const API_BASE_URL = process.env.EXPO_PUBLIC_API_BASE_URL ?? 'http://localhost:8000';
const { width: SCREEN_WIDTH } = Dimensions.get('window');

type CollectionCard = {
  id: number;
  scryfall_id: string;
  name: string;
  set_name: string;
  set_code: string;
  rarity: string;
  type_line: string;
  mana_cost: string | null;
  color_identity: string[];
  image_uri: string | null;
  pivot: {
    quantity: number;
    foil: boolean;
  };
};

type Deck = {
  id: number;
  name: string;
  format: string | null;
  cards_count: number;
};

// ─── Color filter config ───────────────────────────────────────────────────

type ColorFilter = 'all' | 'W' | 'U' | 'B' | 'R' | 'G' | 'M' | 'C';

const MANA_ICON_URL: Partial<Record<ColorFilter, string>> = {
  W: 'https://svgs.scryfall.io/card-symbols/W.svg',
  U: 'https://svgs.scryfall.io/card-symbols/U.svg',
  B: 'https://svgs.scryfall.io/card-symbols/B.svg',
  R: 'https://svgs.scryfall.io/card-symbols/R.svg',
  G: 'https://svgs.scryfall.io/card-symbols/G.svg',
  C: 'https://svgs.scryfall.io/card-symbols/C.svg',
};

const COLOR_FILTERS: { key: ColorFilter; label: string; bg: string; border: string; text: string }[] = [
  { key: 'all', label: 'All',   bg: '#2a2a3e', border: '#444', text: '#fff' },
  { key: 'W',   label: 'W',    bg: '#2a2a3e', border: '#444', text: '#fff' },
  { key: 'U',   label: 'U',    bg: '#2a2a3e', border: '#444', text: '#fff' },
  { key: 'B',   label: 'B',    bg: '#2a2a3e', border: '#444', text: '#fff' },
  { key: 'R',   label: 'R',    bg: '#2a2a3e', border: '#444', text: '#fff' },
  { key: 'G',   label: 'G',    bg: '#2a2a3e', border: '#444', text: '#fff' },
  { key: 'M',   label: 'Multi', bg: '#c8960c', border: '#a07a08', text: '#fff' },
  { key: 'C',   label: 'C',    bg: '#2a2a3e', border: '#444', text: '#fff' },
];

function matchesColor(card: CollectionCard, filter: ColorFilter): boolean {
  if (filter === 'all') return true;
  const ci = card.color_identity ?? [];
  if (filter === 'C') return ci.length === 0;
  if (filter === 'M') return ci.length >= 2;
  return ci.length === 1 && ci[0] === filter;
}

// ─── Card type grouping ────────────────────────────────────────────────────

const TYPE_ORDER = [
  'Planeswalker',
  'Creature',
  'Battle',
  'Instant',
  'Sorcery',
  'Enchantment',
  'Artifact',
  'Land',
  'Other',
];

function cardTypeGroup(typeLine: string): string {
  if (!typeLine) return 'Other';
  for (const t of TYPE_ORDER.slice(0, -1)) {
    if (typeLine.includes(t)) return t;
  }
  return 'Other';
}

// ─── List items ────────────────────────────────────────────────────────────

type HeaderItem = { kind: 'header'; title: string; count: number };
type RowItem    = { kind: 'row';    cards: CollectionCard[] };
type ListItem   = HeaderItem | RowItem;

function buildListData(cards: CollectionCard[], collapsed: Set<string>): ListItem[] {
  // Group by type
  const groups: Record<string, CollectionCard[]> = {};
  for (const card of cards) {
    const group = cardTypeGroup(card.type_line);
    if (!groups[group]) groups[group] = [];
    groups[group].push(card);
  }

  const items: ListItem[] = [];
  for (const type of TYPE_ORDER) {
    const group = groups[type];
    if (!group || group.length === 0) continue;
    items.push({ kind: 'header', title: type, count: group.length });
    if (collapsed.has(type)) continue;
    // chunk into rows of 2
    for (let i = 0; i < group.length; i += 2) {
      items.push({ kind: 'row', cards: group.slice(i, i + 2) });
    }
  }
  return items;
}

// ─── Main screen ──────────────────────────────────────────────────────────

export default function CollectionScreen() {
  const { token } = useAuth();
  const router = useRouter();
  const [cards, setCards] = useState<CollectionCard[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  // Filters
  const [search, setSearch] = useState('');
  const [colorFilter, setColorFilter] = useState<ColorFilter>('all');
  const [collapsedSections, setCollapsedSections] = useState<Set<string>>(new Set());

  const toggleSection = (title: string) => {
    setCollapsedSections(prev => {
      const next = new Set(prev);
      if (next.has(title)) next.delete(title);
      else next.add(title);
      return next;
    });
  };

  // Card detail modal
  const [selectedCard, setSelectedCard] = useState<CollectionCard | null>(null);
  const [updating, setUpdating] = useState(false);

  // Deck picker
  const [decks, setDecks] = useState<Deck[]>([]);
  const [loadingDecks, setLoadingDecks] = useState(false);
  const [addingToDeck, setAddingToDeck] = useState(false);
  const [modalView, setModalView] = useState<'card' | 'deck-picker'>('card');

  const fetchCollection = useCallback(async () => {
    try {
      const response = await fetch(`${API_BASE_URL}/api/collection`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });
      if (!response.ok) return;
      const data = await response.json();
      setCards(data);
    } catch {
      // network error — leave previous state intact
    }
  }, [token]);

  useEffect(() => {
    fetchCollection().finally(() => setLoading(false));
  }, [fetchCollection]);

  async function handleRefresh() {
    setRefreshing(true);
    await fetchCollection();
    setRefreshing(false);
  }

  // Filtered + grouped list data
  const listData = useMemo<ListItem[]>(() => {
    const q = search.trim().toLowerCase();
    const filtered = cards.filter(card => {
      const matchesSearch = !q || card.name.toLowerCase().includes(q);
      return matchesSearch && matchesColor(card, colorFilter);
    });
    return buildListData(filtered, collapsedSections);
  }, [cards, search, colorFilter, collapsedSections]);

  const totalFiltered = useMemo(
    () => listData.filter(i => i.kind === 'row').reduce((sum, i) => sum + (i as RowItem).cards.length, 0),
    [listData],
  );

  async function handleQuantityChange(delta: number) {
    if (!selectedCard || updating) return;

    const newQuantity = selectedCard.pivot.quantity + delta;
    const updatedCard = { ...selectedCard, pivot: { ...selectedCard.pivot, quantity: newQuantity } };
    setSelectedCard(updatedCard);
    setCards(prev =>
      newQuantity <= 0
        ? prev.filter(c => !(c.id === selectedCard.id && c.pivot.foil === selectedCard.pivot.foil))
        : prev.map(c =>
            c.id === selectedCard.id && c.pivot.foil === selectedCard.pivot.foil ? updatedCard : c
          )
    );

    setUpdating(true);
    try {
      const response = await fetch(`${API_BASE_URL}/api/collection/${selectedCard.id}`, {
        method: 'PATCH',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({ quantity: newQuantity, foil: selectedCard.pivot.foil }),
      });

      if (!response.ok) throw new Error('Update failed');
      if (newQuantity <= 0) setSelectedCard(null);
    } catch {
      setSelectedCard(selectedCard);
      setCards(prev =>
        prev.map(c =>
          c.id === selectedCard.id && c.pivot.foil === selectedCard.pivot.foil ? selectedCard : c
        )
      );
      Alert.alert('Error', 'Could not update quantity.');
    } finally {
      setUpdating(false);
    }
  }

  const fetchDecks = async () => {
    setLoadingDecks(true);
    try {
      const response = await fetch(`${API_BASE_URL}/api/decks`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });
      if (response.ok) setDecks(await response.json());
    } catch {
      // ignore
    } finally {
      setLoadingDecks(false);
    }
  };

  const handleOpenDeckPicker = () => {
    fetchDecks();
    setModalView('deck-picker');
  };

  const addToDeck = async (deckId: number) => {
    if (!selectedCard) return;
    setAddingToDeck(true);
    try {
      const response = await fetch(`${API_BASE_URL}/api/decks/${deckId}/cards`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({ scryfall_id: selectedCard.scryfall_id, quantity: 1 }),
      });
      if (response.ok) {
        setModalView('card');
        setSelectedCard(null);
        Alert.alert('Success', `${selectedCard.name} added to deck!`);
      } else {
        throw new Error('Failed to add to deck');
      }
    } catch {
      Alert.alert('Error', 'Could not add card to deck.');
    } finally {
      setAddingToDeck(false);
    }
  };

  if (loading) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator color="#6C3CE1" size="large" />
      </View>
    );
  }

  return (
    <View style={styles.container}>
      {/* ── Header ── */}
      <View style={styles.headerRow}>
        <Text style={styles.header}>My Collection</Text>
        {cards.length > 0 && (
          <Text style={styles.headerCount}>
            {totalFiltered}/{cards.length}
          </Text>
        )}
      </View>

      {/* ── Search bar ── */}
      {cards.length > 0 && (
        <View style={styles.searchBar}>
          <Ionicons name="search" size={16} color="#666" style={styles.searchIcon} />
          <TextInput
            style={styles.searchInput}
            placeholder="Search by name…"
            placeholderTextColor="#555"
            value={search}
            onChangeText={setSearch}
            returnKeyType="search"
            clearButtonMode="while-editing"
            autoCorrect={false}
          />
          {search.length > 0 && Platform.OS === 'android' && (
            <TouchableOpacity onPress={() => setSearch('')} hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }}>
              <Ionicons name="close-circle" size={16} color="#666" />
            </TouchableOpacity>
          )}
        </View>
      )}

      {/* ── Color filter row ── */}
      {cards.length > 0 && (
        <ScrollView
          horizontal
          showsHorizontalScrollIndicator={false}
          style={styles.colorFilterScroll}
          contentContainerStyle={styles.colorFilterRow}
        >
          {COLOR_FILTERS.map(cf => {
            const iconUrl = MANA_ICON_URL[cf.key];
            return (
              <TouchableOpacity
                key={cf.key}
                style={[
                  iconUrl ? styles.colorChipIcon : styles.colorChip,
                  !iconUrl && { backgroundColor: cf.bg, borderColor: cf.border },
                  colorFilter === cf.key && styles.colorChipActive,
                ]}
                onPress={() => setColorFilter(cf.key)}
                activeOpacity={0.7}
              >
                {iconUrl ? (
                  <SvgUri width={22} height={22} uri={iconUrl} />
                ) : (
                  <Text style={[styles.colorChipText, { color: cf.text }]}>{cf.label}</Text>
                )}
              </TouchableOpacity>
            );
          })}
        </ScrollView>
      )}

      {/* ── Card grid ── */}
      <FlatList
        data={listData}
        keyExtractor={(item, index) =>
          item.kind === 'header' ? `header-${item.title}` : `row-${index}`
        }
        contentContainerStyle={listData.length === 0 ? styles.emptyContent : styles.listContent}
        refreshing={refreshing}
        onRefresh={handleRefresh}
        ListEmptyComponent={
          cards.length === 0 ? (
            <View style={styles.emptyState}>
              <Text style={styles.emptyTitle}>No cards yet</Text>
              <Text style={styles.emptySubtitle}>Scan your first card to start your collection.</Text>
              <TouchableOpacity style={styles.scanCta} onPress={() => router.push('/(tabs)/scan')}>
                <Text style={styles.scanCtaText}>Scan a Card</Text>
              </TouchableOpacity>
            </View>
          ) : (
            <View style={styles.emptyState}>
              <Ionicons name="search-outline" size={40} color="#444" />
              <Text style={styles.emptyTitle}>No matches</Text>
              <Text style={styles.emptySubtitle}>Try a different name or color filter.</Text>
            </View>
          )
        }
        renderItem={({ item }) => {
          if (item.kind === 'header') {
            const isCollapsed = collapsedSections.has(item.title);
            return (
              <TouchableOpacity
                style={styles.sectionHeader}
                onPress={() => toggleSection(item.title)}
                activeOpacity={0.7}
              >
                <Text style={styles.sectionHeaderText}>{item.title}</Text>
                <View style={styles.sectionHeaderRight}>
                  <Text style={styles.sectionHeaderCount}>{item.count}</Text>
                  <Ionicons
                    name={isCollapsed ? 'chevron-forward' : 'chevron-down'}
                    size={14}
                    color="#555"
                  />
                </View>
              </TouchableOpacity>
            );
          }
          return (
            <View style={styles.row}>
              {item.cards.map(card => (
                <TouchableOpacity
                  key={`${card.scryfall_id}-${card.pivot.foil}`}
                  onPress={() => { setModalView('card'); setSelectedCard(card); }}
                  activeOpacity={0.8}
                >
                  <CardItem card={card} />
                </TouchableOpacity>
              ))}
              {/* spacer when odd card in row */}
              {item.cards.length === 1 && <View style={{ width: CARD_WIDTH }} />}
            </View>
          );
        }}
      />

      {/* ── Card Detail / Deck Picker modal ── */}
      <Modal
        visible={!!selectedCard}
        animationType="slide"
        transparent={true}
        onRequestClose={() => {
          if (modalView === 'deck-picker') setModalView('card');
          else setSelectedCard(null);
        }}
      >
        <View style={styles.modalOverlay}>
          <View style={styles.modalContent}>
            {selectedCard && modalView === 'card' && (
              <>
                <View style={styles.modalHeader}>
                  <Text style={styles.modalTitle} numberOfLines={1}>{selectedCard.name}</Text>
                  <TouchableOpacity style={styles.closeButton} onPress={() => setSelectedCard(null)}>
                    <Ionicons name="close" size={24} color="#fff" />
                  </TouchableOpacity>
                </View>

                <ScrollView contentContainerStyle={styles.modalScroll}>
                  {selectedCard.image_uri ? (
                    <Image
                      source={{ uri: selectedCard.image_uri }}
                      style={styles.modalImage}
                      resizeMode="contain"
                    />
                  ) : (
                    <View style={[styles.modalImage, styles.modalImagePlaceholder]}>
                      <Ionicons name="image-outline" size={48} color="#444" />
                    </View>
                  )}

                  <View style={styles.modalInfo}>
                    <Text style={styles.modalType}>{selectedCard.type_line}</Text>
                    <Text style={styles.modalSet}>
                      {selectedCard.set_name} • {selectedCard.rarity}
                    </Text>
                    {selectedCard.pivot.foil ? (
                      <View style={styles.foilBadgeModal}>
                        <Text style={styles.foilBadgeText}>Foil</Text>
                      </View>
                    ) : null}
                  </View>

                  {/* Quantity Controls */}
                  <View style={styles.quantitySection}>
                    <Text style={styles.quantityLabel}>Owned</Text>
                    <View style={styles.quantityControls}>
                      <TouchableOpacity
                        style={[styles.qtyButton, updating && styles.qtyButtonDisabled]}
                        onPress={() => handleQuantityChange(-1)}
                        disabled={updating}
                      >
                        <Ionicons name="remove" size={20} color="#fff" />
                      </TouchableOpacity>
                      <Text style={styles.quantityValue}>{selectedCard.pivot.quantity}</Text>
                      <TouchableOpacity
                        style={[styles.qtyButton, styles.qtyButtonAdd, updating && styles.qtyButtonDisabled]}
                        onPress={() => handleQuantityChange(1)}
                        disabled={updating}
                      >
                        <Ionicons name="add" size={20} color="#fff" />
                      </TouchableOpacity>
                    </View>
                    {updating && <ActivityIndicator size="small" color="#6C3CE1" style={{ marginTop: 4 }} />}
                  </View>

                  {/* Add to Deck */}
                  <TouchableOpacity
                    style={styles.deckButton}
                    onPress={handleOpenDeckPicker}
                    disabled={updating || addingToDeck}
                  >
                    <Ionicons name="albums" size={20} color="#fff" />
                    <Text style={styles.deckButtonText}>Add to Deck</Text>
                  </TouchableOpacity>
                </ScrollView>
              </>
            )}

            {selectedCard && modalView === 'deck-picker' && (
              <>
                <View style={[styles.modalHeader, { paddingHorizontal: 20 }]}>
                  <TouchableOpacity style={styles.closeButton} onPress={() => setModalView('card')}>
                    <Ionicons name="arrow-back" size={22} color="#fff" />
                  </TouchableOpacity>
                  <Text style={[styles.modalTitle, { marginLeft: 8 }]}>Choose a Deck</Text>
                  <TouchableOpacity style={styles.closeButton} onPress={() => setSelectedCard(null)}>
                    <Ionicons name="close" size={24} color="#fff" />
                  </TouchableOpacity>
                </View>

                {loadingDecks ? (
                  <ActivityIndicator color="#6C3CE1" style={{ marginVertical: 40 }} />
                ) : decks.length === 0 ? (
                  <View style={styles.emptyPicker}>
                    <Text style={styles.emptyPickerText}>You don't have any decks yet.</Text>
                    <TouchableOpacity
                      style={styles.createDeckLink}
                      onPress={() => {
                        setSelectedCard(null);
                        router.push('/(tabs)/decks');
                      }}
                    >
                      <Text style={styles.createDeckLinkText}>Create your first deck</Text>
                    </TouchableOpacity>
                  </View>
                ) : (
                  <FlatList
                    data={decks}
                    keyExtractor={(item) => item.id.toString()}
                    renderItem={({ item }) => (
                      <TouchableOpacity
                        style={styles.deckSelectItem}
                        onPress={() => addToDeck(item.id)}
                        disabled={addingToDeck}
                      >
                        <View>
                          <Text style={styles.deckSelectName}>{item.name}</Text>
                          <Text style={styles.deckSelectMeta}>
                            {item.format ? item.format.toUpperCase() : 'CASUAL'} • {item.cards_count} cards
                          </Text>
                        </View>
                        {addingToDeck
                          ? <ActivityIndicator size="small" color="#6C3CE1" />
                          : <Ionicons name="add-circle" size={24} color="#6C3CE1" />
                        }
                      </TouchableOpacity>
                    )}
                    contentContainerStyle={{ paddingHorizontal: 20 }}
                    style={{ maxHeight: 400 }}
                  />
                )}
              </>
            )}
          </View>
        </View>
      </Modal>
    </View>
  );
}

function CardItem({ card }: { card: CollectionCard }) {
  return (
    <View style={styles.cardItem}>
      {card.image_uri ? (
        <Image source={{ uri: card.image_uri }} style={styles.cardImage} resizeMode="cover" />
      ) : (
        <View style={styles.cardImagePlaceholder}>
          <Text style={styles.cardImagePlaceholderText}>{card.name}</Text>
        </View>
      )}
      <View style={styles.cardInfo}>
        <Text style={styles.cardName} numberOfLines={1}>{card.name}</Text>
        <Text style={styles.cardMeta} numberOfLines={1}>{card.set_name}</Text>
        <View style={styles.cardBadges}>
          <View style={styles.quantityBadge}>
            <Text style={styles.quantityText}>{`\u00D7${card.pivot.quantity}`}</Text>
          </View>
          {card.pivot.foil ? (
            <View style={styles.foilBadge}>
              <Text style={styles.foilText}>Foil</Text>
            </View>
          ) : null}
        </View>
      </View>
    </View>
  );
}

const CARD_WIDTH = 160;
const CARD_HEIGHT = 224;

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#0f0f1a',
  },
  centered: {
    flex: 1,
    backgroundColor: '#0f0f1a',
    alignItems: 'center',
    justifyContent: 'center',
  },

  // ── Header ──
  headerRow: {
    flexDirection: 'row',
    alignItems: 'baseline',
    paddingHorizontal: 16,
    paddingTop: 16,
    paddingBottom: 12,
    gap: 8,
  },
  header: {
    color: '#fff',
    fontSize: 22,
    fontWeight: '700',
  },
  headerCount: {
    color: '#666',
    fontSize: 13,
    fontWeight: '500',
  },

  // ── Search ──
  searchBar: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#1a1a2e',
    marginHorizontal: 16,
    marginBottom: 10,
    borderRadius: 10,
    paddingHorizontal: 12,
    height: 40,
    borderWidth: 1,
    borderColor: '#2a2a3e',
  },
  searchIcon: {
    marginRight: 8,
  },
  searchInput: {
    flex: 1,
    color: '#fff',
    fontSize: 14,
    paddingVertical: 0,
  },

  // ── Color filter ──
  colorFilterScroll: {
    height: 48,
    flexGrow: 0,
    marginBottom: 4,
  },
  colorFilterRow: {
    paddingHorizontal: 16,
    paddingBottom: 4,
    gap: 8,
    alignItems: 'center',
    height: 48,
  },
  colorChip: {
    height: 32,
    paddingHorizontal: 12,
    borderRadius: 16,
    borderWidth: 2,
    minWidth: 40,
    alignItems: 'center',
    justifyContent: 'center',
  },
  colorChipIcon: {
    width: 32,
    height: 32,
    borderRadius: 16,
    backgroundColor: 'transparent',
    borderWidth: 2,
    borderColor: 'transparent',
    alignItems: 'center',
    justifyContent: 'center',
    padding: 2,
  },
  colorChipActive: {
    borderColor: '#fff',
    shadowColor: '#fff',
    shadowOpacity: 0.4,
    shadowRadius: 6,
    shadowOffset: { width: 0, height: 0 },
    elevation: 6,
  },
  colorChipText: {
    fontSize: 12,
    fontWeight: '700',
  },

  // ── Section header ──
  sectionHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: 4,
    paddingTop: 8,
    paddingBottom: 10,
  },
  sectionHeaderText: {
    color: '#aaa',
    fontSize: 12,
    fontWeight: '700',
    textTransform: 'uppercase',
    letterSpacing: 1.2,
  },
  sectionHeaderRight: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
  },
  sectionHeaderCount: {
    color: '#555',
    fontSize: 12,
    fontWeight: '600',
  },

  // ── List ──
  listContent: {
    paddingHorizontal: 12,
    paddingBottom: 24,
  },
  emptyContent: {
    flex: 1,
  },
  row: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    marginBottom: 16,
  },

  // ── Card item ──
  cardItem: {
    width: CARD_WIDTH,
    backgroundColor: '#1a1a2e',
    borderRadius: 10,
    overflow: 'hidden',
  },
  cardImage: {
    width: CARD_WIDTH,
    height: CARD_HEIGHT,
  },
  cardImagePlaceholder: {
    width: CARD_WIDTH,
    height: CARD_HEIGHT,
    backgroundColor: '#2a2a3e',
    alignItems: 'center',
    justifyContent: 'center',
    padding: 8,
  },
  cardImagePlaceholderText: {
    color: '#888',
    fontSize: 12,
    textAlign: 'center',
  },
  cardInfo: {
    padding: 8,
  },
  cardName: {
    color: '#fff',
    fontSize: 13,
    fontWeight: '600',
    marginBottom: 2,
  },
  cardMeta: {
    color: '#888',
    fontSize: 11,
    marginBottom: 6,
  },
  cardBadges: {
    flexDirection: 'row',
    gap: 6,
  },
  quantityBadge: {
    backgroundColor: '#2a2a3e',
    paddingHorizontal: 6,
    paddingVertical: 2,
    borderRadius: 4,
  },
  quantityText: {
    color: '#ccc',
    fontSize: 11,
    fontWeight: '600',
  },
  foilBadge: {
    backgroundColor: '#3d2a6e',
    paddingHorizontal: 6,
    paddingVertical: 2,
    borderRadius: 4,
  },
  foilText: {
    color: '#a78bfa',
    fontSize: 11,
    fontWeight: '600',
  },

  // ── Empty state ──
  emptyState: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 40,
    gap: 8,
  },
  emptyTitle: {
    color: '#fff',
    fontSize: 20,
    fontWeight: '700',
    marginBottom: 8,
  },
  emptySubtitle: {
    color: '#888',
    fontSize: 14,
    textAlign: 'center',
    marginBottom: 24,
  },
  scanCta: {
    backgroundColor: '#6C3CE1',
    paddingVertical: 12,
    paddingHorizontal: 28,
    borderRadius: 8,
  },
  scanCtaText: {
    color: '#fff',
    fontWeight: '600',
    fontSize: 15,
  },

  // ── Modal ──
  modalOverlay: {
    flex: 1,
    backgroundColor: 'rgba(0,0,0,0.85)',
    justifyContent: 'flex-end',
  },
  modalContent: {
    backgroundColor: '#1a1a2e',
    borderTopLeftRadius: 24,
    borderTopRightRadius: 24,
    paddingTop: 16,
    paddingBottom: Platform.OS === 'ios' ? 40 : 20,
    height: '85%',
  },
  modalHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingHorizontal: 20,
    paddingBottom: 16,
    borderBottomWidth: 1,
    borderBottomColor: '#2a2a3e',
  },
  modalTitle: {
    color: '#fff',
    fontSize: 18,
    fontWeight: '700',
    flex: 1,
    marginRight: 10,
  },
  closeButton: {
    width: 32,
    height: 32,
    borderRadius: 16,
    backgroundColor: 'rgba(255,255,255,0.1)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  modalScroll: {
    padding: 20,
    alignItems: 'center',
  },
  modalImage: {
    width: SCREEN_WIDTH * 0.7,
    height: (SCREEN_WIDTH * 0.7) * 1.4,
    borderRadius: 12,
    marginBottom: 20,
  },
  modalImagePlaceholder: {
    backgroundColor: '#2a2a3e',
    alignItems: 'center',
    justifyContent: 'center',
  },
  modalInfo: {
    alignItems: 'center',
    marginBottom: 24,
  },
  modalType: {
    color: '#aaa',
    fontSize: 15,
    textAlign: 'center',
    marginBottom: 6,
  },
  modalSet: {
    color: '#666',
    fontSize: 13,
    textAlign: 'center',
    marginBottom: 8,
  },
  foilBadgeModal: {
    backgroundColor: '#3d2a6e',
    paddingHorizontal: 10,
    paddingVertical: 3,
    borderRadius: 6,
    marginTop: 4,
  },
  foilBadgeText: {
    color: '#a78bfa',
    fontSize: 12,
    fontWeight: '600',
  },

  // ── Quantity controls ──
  quantitySection: {
    alignItems: 'center',
    marginBottom: 20,
    width: '100%',
  },
  quantityLabel: {
    color: '#888',
    fontSize: 13,
    fontWeight: '500',
    marginBottom: 10,
    textTransform: 'uppercase',
    letterSpacing: 1,
  },
  quantityControls: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 20,
  },
  qtyButton: {
    width: 44,
    height: 44,
    borderRadius: 22,
    backgroundColor: '#2a2a3e',
    alignItems: 'center',
    justifyContent: 'center',
  },
  qtyButtonAdd: {
    backgroundColor: '#6C3CE1',
  },
  qtyButtonDisabled: {
    opacity: 0.5,
  },
  quantityValue: {
    color: '#fff',
    fontSize: 28,
    fontWeight: '700',
    minWidth: 40,
    textAlign: 'center',
  },

  // ── Deck button ──
  deckButton: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#2a2a3e',
    paddingVertical: 14,
    borderRadius: 12,
    width: '100%',
    gap: 8,
  },
  deckButtonText: {
    color: '#fff',
    fontSize: 15,
    fontWeight: '600',
  },

  // ── Deck picker ──
  deckSelectItem: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingVertical: 12,
    borderBottomWidth: 1,
    borderBottomColor: '#2a2a3e',
  },
  deckSelectName: {
    color: '#fff',
    fontSize: 16,
    fontWeight: '600',
    marginBottom: 2,
  },
  deckSelectMeta: {
    color: '#888',
    fontSize: 12,
  },
  emptyPicker: {
    paddingVertical: 20,
    alignItems: 'center',
  },
  emptyPickerText: {
    color: '#888',
    marginBottom: 12,
  },
  createDeckLink: {
    padding: 8,
  },
  createDeckLinkText: {
    color: '#6C3CE1',
    fontWeight: '600',
  },
});
