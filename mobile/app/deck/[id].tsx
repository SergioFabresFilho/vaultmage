import { API_BASE_URL } from '@/lib/api';
import * as Clipboard from 'expo-clipboard';
import * as FileSystem from 'expo-file-system/legacy';
import * as Sharing from 'expo-sharing';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
  Animated,
  ActivityIndicator,
  Alert,
  Dimensions,
  Image,
  Modal,
  Platform,
  ScrollView,
  SectionList,
  Share,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { SvgUri } from 'react-native-svg';
import { useAuth } from '@/context/AuthContext';

const { width: SCREEN_WIDTH } = Dimensions.get('window');

const FORMATS: Record<string, string> = {
  standard: 'Standard',
  pioneer: 'Pioneer',
  modern: 'Modern',
  legacy: 'Legacy',
  vintage: 'Vintage',
  edh: 'EDH / Commander',
  commander: 'Commander',
  brawl: 'Brawl',
  pauper: 'Pauper',
  casual: 'Casual',
};

const TYPE_ORDER = ['Planeswalker', 'Creature', 'Instant', 'Sorcery', 'Enchantment', 'Artifact', 'Land', 'Other'];

const COLOR_DEFS: Record<string, { bg: string; text: string }> = {
  W: { bg: '#f9faf4', text: '#333' },
  U: { bg: '#0e68ab', text: '#fff' },
  B: { bg: '#2a2a2a', text: '#fff' },
  R: { bg: '#d3202a', text: '#fff' },
  G: { bg: '#00733e', text: '#fff' },
};

type Card = {
  id: number;
  name: string;
  type_line: string;
  mana_cost: string | null;
  image_uri: string | null;
  set_name: string;
  rarity: string;
  price_usd: number | null;
  quantity_required: number;
  owned_quantity: number;
  missing_quantity: number;
  pivot: {
    quantity: number;
    is_sideboard: boolean;
    is_commander: boolean;
  };
};

type Deck = {
  id: number;
  name: string;
  format: string | null;
  description: string | null;
  color_identity: string[] | null;
  cards: Card[];
};

type BuyListItem = {
  card_id: number;
  name: string;
  set_name: string;
  type_line: string;
  mana_cost: string | null;
  image_uri: string | null;
  rarity: string;
  quantity_required: number;
  owned_quantity: number;
  missing_quantity: number;
  price_usd: number | null;
  line_total: number | null;
  is_commander: boolean;
  is_sideboard: boolean;
  priority: 'must-buy' | 'upgrade';
  category: string | null;
  reason_type: string;
  explanation_summary: string;
  budget_status: 'included' | 'deferred_for_budget' | 'unpriced' | 'not_applicable';
};

type BuyList = {
  deck_id: number;
  deck_name: string;
  format: string | null;
  items: BuyListItem[];
  missing_cards_count: number;
  estimated_total: number;
  priced_items_count: number;
  unpriced_items_count: number;
  budget: number | null;
  budget_remaining: number | null;
  recommended_total: number;
  cheapest_completion: {
    items: BuyListItem[];
    missing_cards_count: number;
    estimated_total: number;
    priced_items_count: number;
    unpriced_items_count: number;
  };
  groups: {
    must_buy: BuyListItem[];
    upgrade: BuyListItem[];
    optional: BuyListItem[];
    deferred: BuyListItem[];
  };
};

type Section = {
  title: string;
  data: Card[];
};

function parseManaSymbols(manaCost: string): string[] {
  const matches = manaCost.match(/\{[^}]+\}/g);
  return matches ?? [];
}

function ManaCost({ cost, size = 14 }: { cost: string; size?: number }) {
  const symbols = parseManaSymbols(cost);
  if (symbols.length === 0) return null;
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 1 }}>
      {symbols.map((sym, i) => {
        const code = sym.slice(1, -1).replace(/\//g, '').toUpperCase();
        const uri = `https://svgs.scryfall.io/card-symbols/${code}.svg`;
        return <SvgUri key={`${sym}-${i}`} uri={uri} width={size} height={size} />;
      })}
    </View>
  );
}

function getCardType(typeLine: string): string {
  if (typeLine.includes('Planeswalker')) return 'Planeswalker';
  if (typeLine.includes('Creature')) return 'Creature';
  if (typeLine.includes('Instant')) return 'Instant';
  if (typeLine.includes('Sorcery')) return 'Sorcery';
  if (typeLine.includes('Enchantment')) return 'Enchantment';
  if (typeLine.includes('Artifact')) return 'Artifact';
  if (typeLine.includes('Land')) return 'Land';
  return 'Other';
}

function buildSections(cards: Card[], format?: string | null): Section[] {
  const isCommander = format === 'commander' || format === 'edh';
  const commanders = isCommander ? cards.filter((c) => !c.pivot.is_sideboard && c.pivot.is_commander) : [];
  const mainboard = cards.filter((c) => !c.pivot.is_sideboard && !c.pivot.is_commander);
  const sideboard = cards.filter((c) => !!c.pivot.is_sideboard);

  const grouped: Record<string, Card[]> = {};
  for (const card of mainboard) {
    const type = getCardType(card.type_line);
    if (!grouped[type]) grouped[type] = [];
    grouped[type].push(card);
  }

  const sections: Section[] = [];

  if (commanders.length > 0) {
    sections.push({
      title: `Commander (${commanders.reduce((sum, c) => sum + c.pivot.quantity, 0)})`,
      data: commanders.sort((a, b) => a.name.localeCompare(b.name)),
    });
  }

  TYPE_ORDER.filter((t) => grouped[t]?.length > 0).forEach((t) => {
    sections.push({
      title: `${t === 'Sorcery' ? 'Sorceries' : `${t}s`} (${grouped[t].reduce((sum, c) => sum + c.pivot.quantity, 0)})`,
      data: grouped[t].sort((a, b) => a.name.localeCompare(b.name)),
    });
  });

  if (sideboard.length > 0) {
    sections.push({
      title: `Sideboard (${sideboard.reduce((sum, c) => sum + c.pivot.quantity, 0)})`,
      data: sideboard.sort((a, b) => a.name.localeCompare(b.name)),
    });
  }

  return sections;
}

function ownershipLabel(card: Card): string {
  if (card.missing_quantity <= 0) {
    return 'Complete';
  }

  if (card.owned_quantity <= 0) {
    return `Missing ${card.missing_quantity}`;
  }

  return `Own ${card.owned_quantity} / Need ${card.quantity_required}`;
}

function ownershipStyle(card: Card) {
  if (card.missing_quantity <= 0) {
    return styles.ownershipBadgeComplete;
  }

  if (card.owned_quantity <= 0) {
    return styles.ownershipBadgeMissing;
  }

  return styles.ownershipBadgePartial;
}

function buildBuyListExportText(buyList: BuyList): string {
  const sections = [
    { label: 'Must Buy', items: buyList.groups.must_buy },
    { label: 'Upgrade', items: buyList.groups.upgrade },
    { label: 'Deferred', items: buyList.groups.deferred },
  ];

  const lines = [
    `${buyList.deck_name} Buy List`,
    buyList.format ? `Format: ${buyList.format}` : null,
    `Missing cards: ${buyList.missing_cards_count}`,
    `Estimated total: $${buyList.estimated_total.toFixed(2)}`,
    `Cheapest completion: $${buyList.cheapest_completion.estimated_total.toFixed(2)}`,
    buyList.cheapest_completion.unpriced_items_count > 0
      ? `Completion path has ${buyList.cheapest_completion.unpriced_items_count} unpriced item${buyList.cheapest_completion.unpriced_items_count === 1 ? '' : 's'}.`
      : null,
    buyList.budget != null ? `Budget: $${buyList.budget.toFixed(2)}` : null,
    buyList.budget != null ? `Recommended total: $${buyList.recommended_total.toFixed(2)}` : null,
    buyList.budget_remaining != null ? `Budget remaining: $${buyList.budget_remaining.toFixed(2)}` : null,
    buyList.unpriced_items_count > 0
      ? `Unpriced items: ${buyList.unpriced_items_count}`
      : null,
    '',
    ...sections.flatMap((section) => {
      if (section.items.length === 0) {
        return [];
      }

      return [
        `${section.label}:`,
        ...section.items.map((item) => {
          const priceText = item.line_total != null
            ? ` - $${item.line_total.toFixed(2)} total`
            : ' - no price';

          return `${item.missing_quantity}x ${item.name}${priceText}${item.explanation_summary ? ` — ${item.explanation_summary}` : ''}`;
        }),
        '',
      ];
    }),
  ];

  return lines.filter(Boolean).join('\n');
}

function buyListItemCategory(item: BuyListItem): string {
  if (item.is_commander) return 'commander';
  if (item.is_sideboard) return 'sideboard';
  return 'mainboard';
}

function buyListItemPriority(item: BuyListItem): string {
  return item.priority;
}

function buyListSectionCopy(key: 'must_buy' | 'upgrade' | 'deferred'): string {
  if (key === 'must_buy') return 'Required to finish the current deck list.';
  if (key === 'upgrade') return 'Helpful improvements that are not required for completion.';
  return 'Cards that did not fit the current budget or recommendation window.';
}

function buyListStatusLabel(item: BuyListItem): string | null {
  if (item.budget_status === 'deferred_for_budget') return 'Deferred by budget';
  if (item.budget_status === 'unpriced') return 'Missing price data';
  if (item.priority === 'must-buy') return 'Required';
  if (item.priority === 'upgrade') return 'Upgrade';
  return null;
}

function buyListStatusStyle(item: BuyListItem) {
  if (item.budget_status === 'deferred_for_budget') return styles.buyListStatusDeferred;
  if (item.budget_status === 'unpriced') return styles.buyListStatusUnpriced;
  if (item.priority === 'must-buy') return styles.buyListStatusRequired;
  return styles.buyListStatusUpgrade;
}

function csvEscape(value: string | number | boolean | null): string {
  if (value === null) {
    return '';
  }

  const text = String(value);
  if (!text.includes(',') && !text.includes('"') && !text.includes('\n')) {
    return text;
  }

  return `"${text.replace(/"/g, '""')}"`;
}

function buildBuyListCsv(buyList: BuyList): string {
  const header = [
    'name',
    'set_name',
    'type_line',
    'missing_quantity',
    'owned_quantity',
    'quantity_required',
    'priority',
    'category',
    'price_usd',
    'line_total',
    'is_commander',
    'is_sideboard',
  ];

  const rows = buyList.items.map((item) => [
    item.name,
    item.set_name,
    item.type_line,
    item.missing_quantity,
    item.owned_quantity,
    item.quantity_required,
    buyListItemPriority(item),
    buyListItemCategory(item),
    item.price_usd,
    item.line_total,
    item.is_commander,
    item.is_sideboard,
  ]);

  return [header, ...rows]
    .map((row) => row.map((value) => csvEscape(value)).join(','))
    .join('\n');
}

export default function DeckViewScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { token } = useAuth();
  const router = useRouter();

  const [deck, setDeck] = useState<Deck | null>(null);
  const [loading, setLoading] = useState(true);
  const [selectedCard, setSelectedCard] = useState<Card | null>(null);
  const [removing, setRemoving] = useState(false);
  const [deletingDeck, setDeletingDeck] = useState(false);
  const [buyList, setBuyList] = useState<BuyList | null>(null);
  const [buyListVisible, setBuyListVisible] = useState(false);
  const [buyListLoading, setBuyListLoading] = useState(false);
  const [buyListBudget, setBuyListBudget] = useState<number | null>(null);
  const [toastMessage, setToastMessage] = useState<string | null>(null);
  const toastOpacity = useRef(new Animated.Value(0)).current;

  const fetchDeck = useCallback(async () => {
    try {
      const res = await fetch(`${API_BASE_URL}/api/decks/${id}`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });
      if (!res.ok) return;
      setDeck(await res.json());
    } catch {
      // network error
    }
  }, [id, token]);

  useEffect(() => {
    fetchDeck().finally(() => setLoading(false));
  }, [fetchDeck]);

  function showToast(message: string) {
    setToastMessage(message);
    toastOpacity.stopAnimation();
    toastOpacity.setValue(0);

    Animated.sequence([
      Animated.timing(toastOpacity, { toValue: 1, duration: 180, useNativeDriver: true }),
      Animated.delay(1800),
      Animated.timing(toastOpacity, { toValue: 0, duration: 240, useNativeDriver: true }),
    ]).start(() => setToastMessage(null));
  }

  async function handleRemoveCard(card: Card) {
    setRemoving(true);
    try {
      const res = await fetch(`${API_BASE_URL}/api/decks/${id}/cards/${card.id}`, {
        method: 'DELETE',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });
      if (!res.ok) throw new Error('Failed to remove card');
      setSelectedCard(null);
      await fetchDeck();
    } catch {
      Alert.alert('Error', 'Failed to remove card from deck.');
    } finally {
      setRemoving(false);
    }
  }

  function confirmRemove(card: Card) {
    Alert.alert('Remove Card', `Remove ${card.name} from the deck?`, [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Remove', style: 'destructive', onPress: () => handleRemoveCard(card) },
    ]);
  }

  async function handleDeleteDeck() {
    if (!deck) return;

    setDeletingDeck(true);
    try {
      const res = await fetch(`${API_BASE_URL}/api/decks/${deck.id}`, {
        method: 'DELETE',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });

      if (!res.ok) {
        throw new Error('Failed to delete deck');
      }

      router.replace('/decks');
    } catch {
      Alert.alert('Error', 'Failed to delete deck.');
    } finally {
      setDeletingDeck(false);
    }
  }

  async function openBuyList(nextBudget: number | null = buyListBudget, openModal: boolean = true) {
    setBuyListLoading(true);

    try {
      const query = nextBudget != null ? `?budget=${encodeURIComponent(nextBudget.toFixed(2))}` : '';
      const res = await fetch(`${API_BASE_URL}/api/decks/${id}/buy-list${query}`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });

      if (!res.ok) {
        throw new Error('Failed to load buy list');
      }

      const data: BuyList = await res.json();
      setBuyList(data);
      setBuyListBudget(data.budget);
      if (openModal) {
        setBuyListVisible(true);
      }
    } catch {
      Alert.alert('Error', 'Failed to load the buy list for this deck.');
    } finally {
      setBuyListLoading(false);
    }
  }

  async function handleShareBuyList() {
    if (!buyList) return;

    try {
      await Share.share({
        title: `${buyList.deck_name} Buy List`,
        message: buildBuyListExportText(buyList),
      });
      showToast('Share sheet opened.');
    } catch {
      Alert.alert('Error', 'Failed to share the buy list.');
    }
  }

  async function handleCopyBuyList() {
    if (!buyList) return;

    try {
      await Clipboard.setStringAsync(buildBuyListExportText(buyList));
      showToast('Buy list copied to clipboard.');
    } catch {
      Alert.alert('Error', 'Failed to copy the buy list.');
    }
  }

  async function handleExportBuyListCsv() {
    if (!buyList) return;

    try {
      const fileUri = `${FileSystem.cacheDirectory ?? FileSystem.documentDirectory}buy-list-${buyList.deck_id}.csv`;
      await FileSystem.writeAsStringAsync(fileUri, buildBuyListCsv(buyList), {
        encoding: FileSystem.EncodingType.UTF8,
      });

      const canShare = await Sharing.isAvailableAsync();
      if (!canShare) {
        throw new Error('Sharing unavailable');
      }

      await Sharing.shareAsync(fileUri, {
        mimeType: 'text/csv',
        dialogTitle: `${buyList.deck_name} Buy List CSV`,
        UTI: 'public.comma-separated-values-text',
      });
      showToast('CSV export ready to share.');
    } catch {
      Alert.alert('Error', 'Failed to export the buy list CSV.');
    }
  }

  async function applyBuyListBudget(nextBudget: number | null) {
    await openBuyList(nextBudget, false);
  }

  function confirmDeleteDeck() {
    if (!deck || deletingDeck) return;

    Alert.alert('Delete Deck', `Delete ${deck.name}? This cannot be undone.`, [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Delete', style: 'destructive', onPress: handleDeleteDeck },
    ]);
  }

  const totalCards = deck?.cards.reduce((sum, c) => sum + c.pivot.quantity, 0) ?? 0;
  const totalPrice = deck?.cards.reduce((sum, c) => sum + (c.price_usd ?? 0) * c.pivot.quantity, 0) ?? 0;
  const sections = deck ? buildSections(deck.cards, deck.format) : [];

  if (loading) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator color="#6C3CE1" size="large" />
      </View>
    );
  }

  if (!deck) {
    return (
      <View style={styles.centered}>
        <Text style={styles.errorText}>Deck not found.</Text>
        <TouchableOpacity onPress={() => router.back()} style={styles.backButton}>
          <Text style={styles.backButtonText}>Go Back</Text>
        </TouchableOpacity>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      {/* Header */}
      <View style={styles.header}>
        <TouchableOpacity onPress={() => router.back()} style={styles.backBtn}>
          <Ionicons name="chevron-back" size={24} color="#fff" />
        </TouchableOpacity>
        <View style={styles.headerInfo}>
          <Text style={styles.deckName} numberOfLines={1}>
            {deck.name}
          </Text>
          <View style={styles.headerMetaRow}>
            <Text style={styles.deckMeta}>
              {FORMATS[deck.format ?? ''] ?? (deck.format ?? 'Casual')} • {totalCards} cards
            </Text>
            {totalPrice > 0 && (
              <Text style={styles.deckPrice}>${totalPrice.toFixed(2)}</Text>
            )}
            {deck.color_identity && deck.color_identity.length > 0 && (
              <View style={styles.colorPips}>
                {deck.color_identity.map((c) => {
                  const def = COLOR_DEFS[c];
                  if (!def) return null;
                  return (
                    <View key={c} style={[styles.colorPip, { backgroundColor: def.bg }]}>
                      <Text style={[styles.colorPipText, { color: def.text }]}>{c}</Text>
                    </View>
                  );
                })}
              </View>
            )}
          </View>
        </View>
        <TouchableOpacity
          onPress={confirmDeleteDeck}
          style={[styles.deleteDeckBtn, deletingDeck && styles.buttonDisabled]}
          disabled={deletingDeck}
        >
          {deletingDeck ? (
            <ActivityIndicator color="#ffb4b4" size="small" />
          ) : (
            <Ionicons name="trash-outline" size={20} color="#ff8c8c" />
          )}
        </TouchableOpacity>
      </View>

      <TouchableOpacity
        style={styles.assistantBtn}
        onPress={() => router.push({ pathname: '/assistant', params: { deckId: String(deck.id), deckName: deck.name } })}
      >
        <Ionicons name="sparkles" size={18} color="#fff" />
        <Text style={styles.assistantBtnText}>Ask AI to improve this deck</Text>
      </TouchableOpacity>

      <TouchableOpacity
        style={[styles.buyListBtn, buyListLoading && styles.buttonDisabled]}
        onPress={() => void openBuyList()}
        disabled={buyListLoading}
      >
        {buyListLoading ? (
          <ActivityIndicator color="#fff" size="small" />
        ) : (
          <>
            <Ionicons name="cart" size={18} color="#fff" />
            <Text style={styles.buyListBtnText}>Buy Missing Cards</Text>
          </>
        )}
      </TouchableOpacity>

      {sections.length === 0 ? (
        <View style={styles.emptyState}>
          <Ionicons name="layers-outline" size={64} color="#433647" />
          <Text style={styles.emptyTitle}>No cards yet</Text>
          <Text style={styles.emptySubtitle}>
            Add cards from your collection or use the AI Assistant to populate this deck.
          </Text>
        </View>
      ) : (
        <SectionList
          sections={sections}
          keyExtractor={(item) => item.id.toString()}
          contentContainerStyle={styles.listContent}
          renderSectionHeader={({ section }) => (
            <View style={styles.sectionHeader}>
              <Text style={styles.sectionTitle}>{section.title}</Text>
            </View>
          )}
          renderItem={({ item }) => (
            <TouchableOpacity style={styles.cardRow} onPress={() => setSelectedCard(item)}>
              <View style={styles.cardThumbContainer}>
                {item.image_uri ? (
                  <Image source={{ uri: item.image_uri }} style={styles.cardThumb} resizeMode="cover" />
                ) : (
                  <View style={styles.cardThumbPlaceholder}>
                    <Ionicons name="image-outline" size={18} color="#433647" />
                  </View>
                )}
                <View style={styles.qtyOverlay}>
                  <Text style={styles.qtyOverlayText}>{item.pivot.quantity}</Text>
                </View>
              </View>
              <View style={styles.cardInfo}>
                <Text style={styles.cardName}>{item.name}</Text>
                <Text style={styles.cardType} numberOfLines={1}>
                  {item.type_line}
                </Text>
                <View style={[styles.ownershipBadge, ownershipStyle(item)]}>
                  <Text style={styles.ownershipBadgeText}>{ownershipLabel(item)}</Text>
                </View>
              </View>
              <View style={{ alignItems: 'flex-end' }}>
                {item.mana_cost ? (
                  <ManaCost cost={item.mana_cost} size={16} />
                ) : null}
                {item.price_usd != null && (
                  <Text style={styles.cardPrice}>${(item.price_usd * item.pivot.quantity).toFixed(2)}</Text>
                )}
              </View>
              <Ionicons name="chevron-forward" size={16} color="#555" style={styles.rowChevron} />
            </TouchableOpacity>
          )}
          stickySectionHeadersEnabled={false}
        />
      )}

      {/* Card Detail Modal */}
      <Modal
        animationType="slide"
        transparent
        visible={!!selectedCard}
        onRequestClose={() => setSelectedCard(null)}
      >
        {selectedCard && (
          <View style={styles.modalOverlay}>
            <View style={styles.modalContent}>
              <View style={styles.modalHeader}>
                <Text style={styles.modalTitle} numberOfLines={1}>
                  {selectedCard.name}
                </Text>
                <TouchableOpacity style={styles.closeButton} onPress={() => setSelectedCard(null)}>
                  <Ionicons name="close" size={24} color="#fff" />
                </TouchableOpacity>
              </View>

              <ScrollView contentContainerStyle={styles.modalScroll}>
                {selectedCard.image_uri ? (
                  <Image
                    source={{ uri: selectedCard.image_uri }}
                    style={styles.cardImage}
                    resizeMode="contain"
                  />
                ) : (
                  <View style={[styles.cardImage, styles.cardImagePlaceholder]}>
                    <Ionicons name="image-outline" size={48} color="#433647" />
                  </View>
                )}

                <View style={styles.modalInfo}>
                  {selectedCard.mana_cost ? <ManaCost cost={selectedCard.mana_cost} size={20} /> : null}
                  <Text style={styles.modalType}>{selectedCard.type_line}</Text>
                  <Text style={styles.modalSet}>{selectedCard.set_name} • {selectedCard.rarity}</Text>
                  {selectedCard.price_usd != null && (
                    <Text style={styles.modalPrice}>${(selectedCard.price_usd * selectedCard.pivot.quantity).toFixed(2)}</Text>
                  )}
                  <View style={[styles.ownershipBadge, styles.modalOwnershipBadge, ownershipStyle(selectedCard)]}>
                    <Text style={styles.ownershipBadgeText}>{ownershipLabel(selectedCard)}</Text>
                  </View>
                  <View style={styles.cardDetailRow}>
                    <View style={styles.qtyBadgeLarge}>
                      <Text style={styles.qtyTextLarge}>×{selectedCard.pivot.quantity}</Text>
                    </View>
                    {!!selectedCard.pivot.is_sideboard && (
                      <View style={styles.sideboardBadge}>
                        <Text style={styles.sideboardText}>Sideboard</Text>
                      </View>
                    )}
                  </View>
                  <Text style={styles.ownershipDetailText}>
                    You own {selectedCard.owned_quantity} of {selectedCard.quantity_required} required copy{selectedCard.quantity_required === 1 ? '' : 'ies'}.
                  </Text>
                </View>
              </ScrollView>

              <View style={styles.modalActions}>
                <TouchableOpacity
                  style={[styles.removeButton, removing && styles.buttonDisabled]}
                  onPress={() => confirmRemove(selectedCard)}
                  disabled={removing}
                >
                  {removing ? (
                    <ActivityIndicator color="#fff" size="small" />
                  ) : (
                    <>
                      <Ionicons name="trash-outline" size={18} color="#fff" style={{ marginRight: 8 }} />
                      <Text style={styles.removeButtonText}>Remove from Deck</Text>
                    </>
                  )}
                </TouchableOpacity>
              </View>
            </View>
          </View>
        )}
      </Modal>

      <Modal
        animationType="slide"
        transparent
        visible={buyListVisible}
        onRequestClose={() => setBuyListVisible(false)}
      >
        <View style={styles.modalOverlay}>
          <View style={styles.modalContent}>
            <View style={styles.modalHeader}>
              <Text style={styles.modalTitle}>Buy Missing Cards</Text>
              <TouchableOpacity style={styles.closeButton} onPress={() => setBuyListVisible(false)}>
                <Ionicons name="close" size={24} color="#fff" />
              </TouchableOpacity>
            </View>

            <ScrollView contentContainerStyle={styles.buyListScroll}>
              <View style={styles.buyListSummaryCard}>
                <Text style={styles.buyListSummaryTitle}>{deck.name}</Text>
                <Text style={styles.buyListSummaryMeta}>
                  {buyList?.missing_cards_count ?? 0} missing card{(buyList?.missing_cards_count ?? 0) === 1 ? '' : 's'}
                </Text>
                <Text style={styles.buyListSummaryTotal}>Estimated total: ${(buyList?.estimated_total ?? 0).toFixed(2)}</Text>
                <Text style={styles.buyListSummaryMeta}>
                  Cheapest completion: ${(buyList?.cheapest_completion.estimated_total ?? 0).toFixed(2)}
                </Text>
                {(buyList?.cheapest_completion.unpriced_items_count ?? 0) > 0 ? (
                  <Text style={styles.buyListSummaryMeta}>
                    Completion path has {buyList?.cheapest_completion.unpriced_items_count} unpriced item{buyList?.cheapest_completion.unpriced_items_count === 1 ? '' : 's'}.
                  </Text>
                ) : null}
                <View style={styles.budgetChipRow}>
                  {[
                    { label: 'No budget', value: null },
                    { label: '$10', value: 10 },
                    { label: '$25', value: 25 },
                    { label: '$50', value: 50 },
                  ].map((option) => {
                    const selected = buyListBudget === option.value;
                    return (
                      <TouchableOpacity
                        key={option.label}
                        style={[styles.budgetChip, selected ? styles.budgetChipSelected : null, buyListLoading ? styles.buttonDisabled : null]}
                        onPress={() => applyBuyListBudget(option.value)}
                        disabled={buyListLoading}
                      >
                        <Text style={[styles.budgetChipText, selected ? styles.budgetChipTextSelected : null]}>
                          {option.label}
                        </Text>
                      </TouchableOpacity>
                    );
                  })}
                </View>
                {buyList?.budget != null ? (
                  <>
                    <Text style={styles.buyListSummaryMeta}>Recommended under budget: ${buyList.recommended_total.toFixed(2)}</Text>
                    <Text style={styles.buyListSummaryMeta}>Budget remaining: ${(buyList.budget_remaining ?? 0).toFixed(2)}</Text>
                  </>
                ) : null}
                {(buyList?.unpriced_items_count ?? 0) > 0 ? (
                  <Text style={styles.buyListSummaryHint}>
                    {buyList?.unpriced_items_count} item{buyList?.unpriced_items_count === 1 ? '' : 's'} missing price data.
                  </Text>
                ) : null}
                {buyList ? (
                  <View style={styles.buyListExportRow}>
                    <TouchableOpacity style={styles.buyListExportBtn} onPress={handleCopyBuyList}>
                      <Ionicons name="copy-outline" size={16} color="#fff" />
                      <Text style={styles.buyListExportBtnText}>Copy as Text</Text>
                    </TouchableOpacity>
                    <TouchableOpacity style={styles.buyListExportBtn} onPress={handleExportBuyListCsv}>
                      <Ionicons name="document-text-outline" size={16} color="#fff" />
                      <Text style={styles.buyListExportBtnText}>Export CSV</Text>
                    </TouchableOpacity>
                    <TouchableOpacity style={styles.buyListExportBtn} onPress={handleShareBuyList}>
                      <Ionicons name="share-social-outline" size={16} color="#fff" />
                      <Text style={styles.buyListExportBtnText}>Share Text</Text>
                    </TouchableOpacity>
                  </View>
                ) : null}
              </View>

              {buyList?.items.length ? (
                [
                  { key: 'must_buy' as const, title: 'Must Buy', items: buyList.groups.must_buy },
                  { key: 'upgrade' as const, title: 'Upgrade', items: buyList.groups.upgrade },
                  { key: 'deferred' as const, title: 'Deferred', items: buyList.groups.deferred },
                ].map((section) => (
                  section.items.length ? (
                    <View key={section.key} style={styles.buyListSection}>
                      <Text style={styles.buyListSectionTitle}>{section.title}</Text>
                      <Text style={styles.buyListSectionHint}>{buyListSectionCopy(section.key)}</Text>
                      {section.items.map((item) => (
                        <View key={`${section.key}-${item.card_id}-${item.name}`} style={styles.buyListItem}>
                          {item.image_uri ? (
                            <Image source={{ uri: item.image_uri }} style={styles.buyListThumb} resizeMode="cover" />
                          ) : (
                            <View style={[styles.buyListThumb, styles.cardThumbPlaceholder]}>
                              <Ionicons name="image-outline" size={18} color="#433647" />
                            </View>
                          )}
                          <View style={styles.buyListInfo}>
                            <Text style={styles.buyListName}>{item.name}</Text>
                            {buyListStatusLabel(item) ? (
                              <View style={[styles.buyListStatusPill, buyListStatusStyle(item)]}>
                                <Text style={styles.buyListStatusText}>{buyListStatusLabel(item)}</Text>
                              </View>
                            ) : null}
                            <Text style={styles.buyListMeta}>
                              Need {item.missing_quantity} · Own {item.owned_quantity} / {item.quantity_required}
                            </Text>
                            <Text style={styles.buyListExplanation}>{item.explanation_summary}</Text>
                            <Text style={styles.buyListType} numberOfLines={1}>{item.type_line}</Text>
                          </View>
                          <View style={styles.buyListPriceCol}>
                            <Text style={styles.buyListLineTotal}>
                              {item.line_total != null ? `$${item.line_total.toFixed(2)}` : 'No price'}
                            </Text>
                            {item.price_usd != null ? (
                              <Text style={styles.buyListUnitPrice}>${item.price_usd.toFixed(2)} each</Text>
                            ) : null}
                          </View>
                        </View>
                      ))}
                    </View>
                  ) : null
                ))
              ) : (
                <View style={styles.buyListEmptyState}>
                  <Ionicons name="checkmark-circle-outline" size={52} color="#7dcea0" />
                  <Text style={styles.buyListEmptyTitle}>No cards left to buy</Text>
                  <Text style={styles.buyListEmptyText}>This deck is fully covered by your collection.</Text>
                </View>
              )}
            </ScrollView>
          </View>
        </View>
      </Modal>

      {toastMessage ? (
        <Animated.View style={[styles.toast, { opacity: toastOpacity }]} pointerEvents="none">
          <Text style={styles.toastText}>{toastMessage}</Text>
        </Animated.View>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#0f0f1a' },
  centered: { flex: 1, backgroundColor: '#0f0f1a', alignItems: 'center', justifyContent: 'center' },
  errorText: { color: '#888', fontSize: 16, marginBottom: 16 },
  backButton: {
    backgroundColor: '#6C3CE1',
    paddingVertical: 10,
    paddingHorizontal: 20,
    borderRadius: 8,
  },
  backButtonText: { color: '#fff', fontWeight: '600' },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingTop: 56,
    paddingBottom: 16,
    paddingHorizontal: 16,
    borderBottomWidth: 1,
    borderBottomColor: '#2a2a3e',
  },
  backBtn: {
    width: 40,
    height: 40,
    borderRadius: 20,
    backgroundColor: '#1a1a2e',
    borderWidth: 1,
    borderColor: '#2a2a3e',
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: 14,
  },
  headerInfo: { flex: 1 },
  deleteDeckBtn: {
    width: 40,
    height: 40,
    borderRadius: 12,
    backgroundColor: '#1a1a2e',
    borderWidth: 1,
    borderColor: '#2a2a3e',
    alignItems: 'center',
    justifyContent: 'center',
    marginLeft: 12,
  },
  deckName: { color: '#fff', fontSize: 20, fontWeight: '700' },
  headerMetaRow: { flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 2 },
  assistantBtn: {
    marginHorizontal: 16,
    marginTop: 12,
    marginBottom: 4,
    backgroundColor: '#6C3CE1',
    borderRadius: 12,
    paddingHorizontal: 14,
    paddingVertical: 12,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
  },
  assistantBtnText: {
    color: '#fff',
    fontSize: 14,
    fontWeight: '700',
  },
  buyListBtn: {
    marginHorizontal: 16,
    marginTop: 8,
    marginBottom: 4,
    backgroundColor: '#1f6f56',
    borderRadius: 12,
    paddingHorizontal: 14,
    paddingVertical: 12,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
  },
  buyListBtnText: {
    color: '#fff',
    fontSize: 14,
    fontWeight: '700',
  },
  deckMeta: { color: '#888', fontSize: 13 },
  deckPrice: { color: '#7dcea0', fontSize: 13, fontWeight: '600', marginTop: 2 },
  colorPips: { flexDirection: 'row', gap: 3 },
  colorPip: {
    width: 16,
    height: 16,
    borderRadius: 8,
    alignItems: 'center',
    justifyContent: 'center',
  },
  colorPipText: { fontSize: 9, fontWeight: '800' },
  listContent: { paddingHorizontal: 16, paddingBottom: 32, paddingTop: 8 },
  sectionHeader: {
    paddingTop: 16,
    paddingBottom: 6,
  },
  sectionTitle: {
    color: '#6C3CE1',
    fontSize: 12,
    fontWeight: '700',
    textTransform: 'uppercase',
    letterSpacing: 0.8,
  },
  cardRow: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#1a1a2e',
    borderRadius: 10,
    paddingVertical: 12,
    paddingHorizontal: 14,
    marginBottom: 6,
    borderWidth: 1,
    borderColor: '#2a2a3e',
  },
  cardThumbContainer: {
    width: 44,
    height: 60,
    borderRadius: 4,
    marginRight: 12,
    overflow: 'hidden',
    position: 'relative',
  },
  cardThumb: {
    width: '100%',
    height: '100%',
  },
  cardThumbPlaceholder: {
    width: '100%',
    height: '100%',
    backgroundColor: '#0f0f1a',
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1,
    borderColor: '#2a2a3e',
    borderRadius: 4,
  },
  qtyOverlay: {
    position: 'absolute',
    bottom: 2,
    right: 2,
    backgroundColor: 'rgba(108, 60, 225, 0.92)',
    borderRadius: 4,
    minWidth: 18,
    paddingHorizontal: 3,
    paddingVertical: 1,
    alignItems: 'center',
  },
  qtyOverlayText: { color: '#fff', fontSize: 11, fontWeight: '700' },
  cardInfo: { flex: 1 },
  cardName: { color: '#fff', fontSize: 15, fontWeight: '600' },
  cardType: { color: '#888', fontSize: 12, marginTop: 2 },
  ownershipBadge: {
    alignSelf: 'flex-start',
    borderRadius: 999,
    paddingHorizontal: 8,
    paddingVertical: 4,
    marginTop: 6,
  },
  ownershipBadgeComplete: {
    backgroundColor: 'rgba(125, 206, 160, 0.16)',
    borderWidth: 1,
    borderColor: 'rgba(125, 206, 160, 0.35)',
  },
  ownershipBadgePartial: {
    backgroundColor: 'rgba(255, 179, 107, 0.16)',
    borderWidth: 1,
    borderColor: 'rgba(255, 179, 107, 0.35)',
  },
  ownershipBadgeMissing: {
    backgroundColor: 'rgba(255, 140, 140, 0.16)',
    borderWidth: 1,
    borderColor: 'rgba(255, 140, 140, 0.35)',
  },
  ownershipBadgeText: { color: '#f3eee8', fontSize: 11, fontWeight: '700' },
  cardPrice: { color: '#7dcea0', fontSize: 12, marginLeft: 6 },
  rowChevron: { marginLeft: 4 },
  emptyState: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 28,
  },
  emptyTitle: { color: '#fff', fontSize: 20, fontWeight: '700', marginTop: 16, marginBottom: 8 },
  emptySubtitle: { color: '#888', fontSize: 14, textAlign: 'center', lineHeight: 20 },
  modalOverlay: { flex: 1, backgroundColor: 'rgba(0,0,0,0.85)', justifyContent: 'flex-end' },
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
  modalTitle: { color: '#fff', fontSize: 18, fontWeight: '700', flex: 1, marginRight: 10 },
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
  buyListScroll: {
    padding: 20,
  },
  cardImage: {
    width: SCREEN_WIDTH * 0.7,
    height: (SCREEN_WIDTH * 0.7) * 1.4,
    borderRadius: 12,
    marginBottom: 20,
  },
  cardImagePlaceholder: {
    backgroundColor: '#2a2a3e',
    alignItems: 'center',
    justifyContent: 'center',
  },
  modalInfo: {
    alignItems: 'center',
    marginBottom: 8,
    gap: 6,
  },
  modalType: { color: '#aaa', fontSize: 15, textAlign: 'center' },
  modalSet: { color: '#666', fontSize: 13, textAlign: 'center' },
  modalPrice: { color: '#7dcea0', fontSize: 15, fontWeight: '600' },
  modalOwnershipBadge: {
    alignSelf: 'center',
    marginTop: 2,
  },
  buyListSummaryCard: {
    backgroundColor: '#0f0f1a',
    borderRadius: 16,
    padding: 16,
    borderWidth: 1,
    borderColor: '#2a2a3e',
    marginBottom: 16,
  },
  buyListSummaryTitle: { color: '#fff', fontSize: 18, fontWeight: '700' },
  buyListSummaryMeta: { color: '#aaa', fontSize: 13, marginTop: 4 },
  buyListSummaryTotal: { color: '#7dcea0', fontSize: 15, fontWeight: '700', marginTop: 8 },
  buyListSummaryHint: { color: '#e8b26b', fontSize: 12, marginTop: 6 },
  budgetChipRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 8,
    marginTop: 12,
  },
  budgetChip: {
    backgroundColor: '#1a1a2e',
    borderRadius: 999,
    paddingHorizontal: 12,
    paddingVertical: 8,
    borderWidth: 1,
    borderColor: '#2a2a3e',
  },
  budgetChipSelected: {
    backgroundColor: '#6C3CE1',
    borderColor: '#6C3CE1',
  },
  budgetChipText: {
    color: '#ccc',
    fontSize: 12,
    fontWeight: '700',
  },
  budgetChipTextSelected: {
    color: '#fff',
  },
  buyListExportRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 8,
    marginTop: 12,
  },
  buyListSection: {
    marginBottom: 16,
  },
  buyListSectionTitle: {
    color: '#f3eee8',
    fontSize: 13,
    fontWeight: '800',
    textTransform: 'uppercase',
    letterSpacing: 0.6,
    marginBottom: 8,
  },
  buyListSectionHint: { color: '#8f8aa3', fontSize: 12, marginBottom: 10, lineHeight: 17 },
  buyListExportBtn: {
    backgroundColor: '#6C3CE1',
    borderRadius: 999,
    paddingHorizontal: 12,
    paddingVertical: 10,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
  },
  buyListExportBtnText: { color: '#fff', fontSize: 13, fontWeight: '700' },
  buyListItem: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#0f0f1a',
    borderRadius: 14,
    padding: 12,
    borderWidth: 1,
    borderColor: '#2a2a3e',
    marginBottom: 10,
  },
  buyListThumb: {
    width: 42,
    height: 58,
    borderRadius: 6,
    marginRight: 12,
  },
  buyListInfo: { flex: 1 },
  buyListName: { color: '#fff', fontSize: 14, fontWeight: '700' },
  buyListStatusPill: {
    alignSelf: 'flex-start',
    borderRadius: 999,
    paddingHorizontal: 8,
    paddingVertical: 4,
    marginTop: 6,
    marginBottom: 2,
    borderWidth: 1,
  },
  buyListStatusRequired: {
    backgroundColor: 'rgba(125, 206, 160, 0.16)',
    borderColor: 'rgba(125, 206, 160, 0.35)',
  },
  buyListStatusUpgrade: {
    backgroundColor: 'rgba(108, 60, 225, 0.18)',
    borderColor: 'rgba(108, 60, 225, 0.35)',
  },
  buyListStatusDeferred: {
    backgroundColor: 'rgba(255, 140, 140, 0.16)',
    borderColor: 'rgba(255, 140, 140, 0.35)',
  },
  buyListStatusUnpriced: {
    backgroundColor: 'rgba(255, 179, 107, 0.16)',
    borderColor: 'rgba(255, 179, 107, 0.35)',
  },
  buyListStatusText: { color: '#f3eee8', fontSize: 10, fontWeight: '800', textTransform: 'uppercase' },
  buyListMeta: { color: '#f3eee8', fontSize: 12, marginTop: 4 },
  buyListExplanation: { color: '#c9c2d6', fontSize: 12, marginTop: 4, lineHeight: 17 },
  buyListType: { color: '#888', fontSize: 12, marginTop: 2 },
  buyListPriceCol: { alignItems: 'flex-end', marginLeft: 12 },
  buyListLineTotal: { color: '#7dcea0', fontSize: 13, fontWeight: '700' },
  buyListUnitPrice: { color: '#888', fontSize: 11, marginTop: 2 },
  buyListEmptyState: {
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: 48,
    paddingHorizontal: 24,
  },
  buyListEmptyTitle: { color: '#fff', fontSize: 18, fontWeight: '700', marginTop: 12 },
  buyListEmptyText: { color: '#888', fontSize: 14, textAlign: 'center', marginTop: 8 },
  modalActions: {
    paddingHorizontal: 20,
    paddingTop: 12,
    borderTopWidth: 1,
    borderTopColor: '#2a2a3e',
  },
  cardDetailRow: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  ownershipDetailText: { color: '#aaa', fontSize: 13, textAlign: 'center' },
  qtyBadgeLarge: {
    backgroundColor: '#6C3CE1',
    borderRadius: 8,
    paddingHorizontal: 12,
    paddingVertical: 5,
  },
  qtyTextLarge: { color: '#fff', fontSize: 14, fontWeight: '700' },
  sideboardBadge: {
    backgroundColor: '#2a2a3e',
    borderRadius: 8,
    paddingHorizontal: 12,
    paddingVertical: 5,
    borderWidth: 1,
    borderColor: '#3a3a5e',
  },
  sideboardText: { color: '#888', fontSize: 13, fontWeight: '600' },
  removeButton: {
    backgroundColor: '#c0392b',
    borderRadius: 12,
    paddingVertical: 14,
    alignItems: 'center',
    justifyContent: 'center',
    flexDirection: 'row',
  },
  removeButtonText: { color: '#fff', fontSize: 15, fontWeight: '700' },
  buttonDisabled: { opacity: 0.55 },
  toast: {
    position: 'absolute',
    left: 20,
    right: 20,
    bottom: Platform.OS === 'ios' ? 42 : 24,
    backgroundColor: 'rgba(17, 24, 39, 0.96)',
    borderRadius: 12,
    borderWidth: 1,
    borderColor: '#2a2a3e',
    paddingHorizontal: 14,
    paddingVertical: 12,
  },
  toastText: {
    color: '#fff',
    fontSize: 13,
    fontWeight: '600',
    textAlign: 'center',
  },
});
