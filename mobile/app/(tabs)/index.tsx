import { API_BASE_URL } from '@/lib/api';
import { useCallback, useEffect, useMemo, useState } from 'react';
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

const { width: SCREEN_WIDTH } = Dimensions.get('window');

type BaseCard = {
  id?: number;
  scryfall_id: string;
  name: string;
  set_name: string;
  set_code: string;
  collector_number?: string;
  rarity: string;
  type_line: string;
  mana_cost: string | null;
  price_usd: number | null;
  color_identity?: string[];
  image_uri: string | null;
};

type CollectionCard = BaseCard & {
  id: number;
  pivot: {
    quantity: number;
    foil: boolean;
  };
};

type SearchCard = BaseCard;

type SelectedCard = CollectionCard | SearchCard;

type Deck = {
  id: number;
  name: string;
  format: string | null;
  cards_sum_quantity: number;
};

type BrowserMode = 'collection' | 'search';
type ColorFilter = 'all' | 'W' | 'U' | 'B' | 'R' | 'G' | 'M' | 'C';
type HeaderItem = { kind: 'header'; title: string; count: number };
type RowItem = { kind: 'row'; cards: CollectionCard[] };
type ListItem = HeaderItem | RowItem;

const CARD_WIDTH = 160;
const CARD_HEIGHT = 224;

const MANA_ICON_URL: Partial<Record<ColorFilter, string>> = {
  W: 'https://svgs.scryfall.io/card-symbols/W.svg',
  U: 'https://svgs.scryfall.io/card-symbols/U.svg',
  B: 'https://svgs.scryfall.io/card-symbols/B.svg',
  R: 'https://svgs.scryfall.io/card-symbols/R.svg',
  G: 'https://svgs.scryfall.io/card-symbols/G.svg',
  C: 'https://svgs.scryfall.io/card-symbols/C.svg',
};

const COLOR_FILTERS: { key: ColorFilter; label: string; bg: string; border: string; text: string }[] = [
  { key: 'all', label: 'All', bg: '#2a2a3e', border: '#444', text: '#fff' },
  { key: 'W', label: 'W', bg: '#2a2a3e', border: '#444', text: '#fff' },
  { key: 'U', label: 'U', bg: '#2a2a3e', border: '#444', text: '#fff' },
  { key: 'B', label: 'B', bg: '#2a2a3e', border: '#444', text: '#fff' },
  { key: 'R', label: 'R', bg: '#2a2a3e', border: '#444', text: '#fff' },
  { key: 'G', label: 'G', bg: '#2a2a3e', border: '#444', text: '#fff' },
  { key: 'M', label: 'Multi', bg: '#c8960c', border: '#a07a08', text: '#fff' },
  { key: 'C', label: 'C', bg: '#2a2a3e', border: '#444', text: '#fff' },
];

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

function matchesColor(card: CollectionCard, filter: ColorFilter): boolean {
  if (filter === 'all') return true;
  const ci = card.color_identity ?? [];
  if (filter === 'C') return ci.length === 0;
  if (filter === 'M') return ci.length >= 2;
  return ci.length === 1 && ci[0] === filter;
}

function cardTypeGroup(typeLine: string): string {
  if (!typeLine) return 'Other';
  for (const type of TYPE_ORDER.slice(0, -1)) {
    if (typeLine.includes(type)) return type;
  }
  return 'Other';
}

function buildListData(cards: CollectionCard[], collapsed: Set<string>): ListItem[] {
  const groups: Record<string, CollectionCard[]> = {};

  for (const card of cards) {
    const group = cardTypeGroup(card.type_line);
    if (!groups[group]) groups[group] = [];
    groups[group].push(card);
  }

  const items: ListItem[] = [];
  for (const type of TYPE_ORDER) {
    const group = groups[type];
    if (!group?.length) continue;
    items.push({ kind: 'header', title: type, count: group.length });
    if (collapsed.has(type)) continue;

    for (let index = 0; index < group.length; index += 2) {
      items.push({ kind: 'row', cards: group.slice(index, index + 2) });
    }
  }

  return items;
}

function isCollectionCard(card: SelectedCard | null): card is CollectionCard {
  return !!card && 'pivot' in card;
}

function ManaCost({ cost, size = 14 }: { cost: string | null; size?: number }) {
  if (!cost) return null;

  const symbols = cost.match(/{([^}]+)}/g) || [];

  return (
    <View style={styles.manaCostContainer}>
      {symbols.map((symbol, index) => {
        const name = symbol.slice(1, -1).replace(/\//g, '').toUpperCase();
        const svgUrl = `https://svgs.scryfall.io/card-symbols/${name}.svg`;
        const uri = `https://images.weserv.nl/?url=${encodeURIComponent(svgUrl)}&output=png&w=${size * 2}`;

        return (
          <Image
            key={`${name}-${index}`}
            source={{ uri }}
            style={{ width: size, height: size, marginLeft: 2 }}
            resizeMode="contain"
          />
        );
      })}
    </View>
  );
}

export default function CollectionScreen() {
  const { token } = useAuth();
  const router = useRouter();

  const [cards, setCards] = useState<CollectionCard[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [mode, setMode] = useState<BrowserMode>('collection');
  const [query, setQuery] = useState('');
  const [debouncedQuery, setDebouncedQuery] = useState('');
  const [colorFilter, setColorFilter] = useState<ColorFilter>('all');
  const [collapsedSections, setCollapsedSections] = useState<Set<string>>(new Set());

  const [searchResults, setSearchResults] = useState<SearchCard[]>([]);
  const [searchLoading, setSearchLoading] = useState(false);
  const [searchError, setSearchError] = useState<string | null>(null);

  const [selectedCard, setSelectedCard] = useState<SelectedCard | null>(null);
  const [modalView, setModalView] = useState<'card' | 'deck-picker'>('card');
  const [updatingCollection, setUpdatingCollection] = useState(false);
  const [decks, setDecks] = useState<Deck[]>([]);
  const [loadingDecks, setLoadingDecks] = useState(false);
  const [addingToDeck, setAddingToDeck] = useState(false);

  const toggleSection = (title: string) => {
    setCollapsedSections(prev => {
      const next = new Set(prev);
      if (next.has(title)) next.delete(title);
      else next.add(title);
      return next;
    });
  };

  const fetchCollection = useCallback(async () => {
    try {
      const response = await fetch(`${API_BASE_URL}/api/collection`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });

      if (!response.ok) return;

      const data = await response.json();
      setCards(data);
    } catch {
      // Leave previous state intact on network errors.
    }
  }, [token]);

  const performSearch = useCallback(async (value: string) => {
    if (!value.trim()) {
      setSearchResults([]);
      setSearchError(null);
      return;
    }

    setSearchLoading(true);
    setSearchError(null);

    try {
      const response = await fetch(`${API_BASE_URL}/api/cards/search?q=${encodeURIComponent(value)}`, {
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
      });

      if (!response.ok) throw new Error('Search failed');

      const data = await response.json();
      setSearchResults(data);
    } catch (error) {
      console.error(error);
      setSearchError('Could not complete search. Please try again.');
    } finally {
      setSearchLoading(false);
    }
  }, [token]);

  useEffect(() => {
    fetchCollection().finally(() => setLoading(false));
  }, [fetchCollection]);

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedQuery(query);
    }, 500);

    return () => clearTimeout(timer);
  }, [query]);

  useEffect(() => {
    if (mode !== 'search') return;
    performSearch(debouncedQuery);
  }, [debouncedQuery, mode, performSearch]);

  const collectionListData = useMemo<ListItem[]>(() => {
    const normalizedQuery = query.trim().toLowerCase();
    const filtered = cards.filter(card => {
      const matchesSearch = !normalizedQuery || card.name.toLowerCase().includes(normalizedQuery);
      return matchesSearch && matchesColor(card, colorFilter);
    });

    return buildListData(filtered, collapsedSections);
  }, [cards, query, colorFilter, collapsedSections]);

  const totalFiltered = useMemo(
    () => collectionListData
      .filter(item => item.kind === 'row')
      .reduce((sum, item) => sum + (item as RowItem).cards.length, 0),
    [collectionListData],
  );

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

  async function handleRefresh() {
    if (mode === 'search') {
      await performSearch(query);
      return;
    }

    setRefreshing(true);
    await fetchCollection();
    setRefreshing(false);
  }

  async function handleQuantityChange(delta: number) {
    if (!isCollectionCard(selectedCard) || updatingCollection) return;

    const previousCard = selectedCard;
    const newQuantity = previousCard.pivot.quantity + delta;
    const updatedCard: CollectionCard = {
      ...previousCard,
      pivot: { ...previousCard.pivot, quantity: newQuantity },
    };

    setSelectedCard(updatedCard);
    setCards(prev =>
      newQuantity <= 0
        ? prev.filter(card => !(card.id === previousCard.id && card.pivot.foil === previousCard.pivot.foil))
        : prev.map(card =>
            card.id === previousCard.id && card.pivot.foil === previousCard.pivot.foil ? updatedCard : card,
          ),
    );

    setUpdatingCollection(true);
    try {
      const response = await fetch(`${API_BASE_URL}/api/collection/${previousCard.id}`, {
        method: 'PATCH',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({ quantity: newQuantity, foil: previousCard.pivot.foil }),
      });

      if (!response.ok) throw new Error('Update failed');
      if (newQuantity <= 0) setSelectedCard(null);
    } catch {
      setSelectedCard(previousCard);
      setCards(prev =>
        prev.map(card =>
          card.id === previousCard.id && card.pivot.foil === previousCard.pivot.foil ? previousCard : card,
        ),
      );
      Alert.alert('Error', 'Could not update quantity.');
    } finally {
      setUpdatingCollection(false);
    }
  }

  const addToCollection = async (card: SearchCard) => {
    setUpdatingCollection(true);
    try {
      const response = await fetch(`${API_BASE_URL}/api/collection`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({
          scryfall_id: card.scryfall_id,
          foil: false,
        }),
      });

      if (!response.ok) throw new Error('Failed to add card');

      await fetchCollection();
      setSelectedCard(null);
      Alert.alert('Success', `${card.name} added to your collection!`);
    } catch {
      Alert.alert('Error', 'Could not add card to collection.');
    } finally {
      setUpdatingCollection(false);
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

      if (!response.ok) throw new Error('Failed to add to deck');

      setModalView('card');
      setSelectedCard(null);
      Alert.alert('Success', `${selectedCard.name} added to deck!`);
    } catch {
      Alert.alert('Error', 'Could not add card to deck.');
    } finally {
      setAddingToDeck(false);
    }
  };

  const headerCount =
    mode === 'collection'
      ? cards.length > 0
        ? `${totalFiltered}/${cards.length}`
        : null
      : debouncedQuery
        ? `${searchResults.length} result${searchResults.length === 1 ? '' : 's'}`
        : null;

  if (loading) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator color="#6C3CE1" size="large" />
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <View style={styles.headerRow}>
        <Text style={styles.header}>Collection</Text>
        {headerCount ? <Text style={styles.headerCount}>{headerCount}</Text> : null}
      </View>

      <View style={styles.modeSwitcher}>
        <TouchableOpacity
          style={[styles.modeButton, mode === 'collection' && styles.modeButtonActive]}
          onPress={() => setMode('collection')}
        >
          <Text style={[styles.modeButtonText, mode === 'collection' && styles.modeButtonTextActive]}>
            My Collection
          </Text>
        </TouchableOpacity>
        <TouchableOpacity
          style={[styles.modeButton, mode === 'search' && styles.modeButtonActive]}
          onPress={() => setMode('search')}
        >
          <Text style={[styles.modeButtonText, mode === 'search' && styles.modeButtonTextActive]}>
            Search All Cards
          </Text>
        </TouchableOpacity>
      </View>

      <View style={styles.searchBar}>
        <Ionicons name="search" size={16} color="#666" style={styles.searchIcon} />
        <TextInput
          style={styles.searchInput}
          placeholder={mode === 'collection' ? 'Search your collection…' : 'Search all cards…'}
          placeholderTextColor="#555"
          value={query}
          onChangeText={setQuery}
          returnKeyType="search"
          clearButtonMode="while-editing"
          autoCorrect={false}
        />
        {query.length > 0 && Platform.OS === 'android' && (
          <TouchableOpacity onPress={() => setQuery('')} hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }}>
            <Ionicons name="close-circle" size={16} color="#666" />
          </TouchableOpacity>
        )}
      </View>

      {mode === 'collection' && cards.length > 0 ? (
        <ScrollView
          horizontal
          showsHorizontalScrollIndicator={false}
          style={styles.colorFilterScroll}
          contentContainerStyle={styles.colorFilterRow}
        >
          {COLOR_FILTERS.map(filter => {
            const iconUrl = MANA_ICON_URL[filter.key];
            return (
              <TouchableOpacity
                key={filter.key}
                style={[
                  iconUrl ? styles.colorChipIcon : styles.colorChip,
                  !iconUrl && { backgroundColor: filter.bg, borderColor: filter.border },
                  colorFilter === filter.key && styles.colorChipActive,
                ]}
                onPress={() => setColorFilter(filter.key)}
                activeOpacity={0.7}
              >
                {iconUrl ? (
                  <SvgUri width={22} height={22} uri={iconUrl} />
                ) : (
                  <Text style={[styles.colorChipText, { color: filter.text }]}>{filter.label}</Text>
                )}
              </TouchableOpacity>
            );
          })}
        </ScrollView>
      ) : null}

      {mode === 'collection' ? (
        <FlatList
          data={collectionListData}
          keyExtractor={(item, index) => item.kind === 'header' ? `header-${item.title}` : `row-${index}`}
          contentContainerStyle={collectionListData.length === 0 ? styles.emptyContent : styles.listContent}
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
                    onPress={() => {
                      setModalView('card');
                      setSelectedCard(card);
                    }}
                    activeOpacity={0.8}
                  >
                    <CardItem card={card} />
                  </TouchableOpacity>
                ))}
                {item.cards.length === 1 ? <View style={{ width: CARD_WIDTH }} /> : null}
              </View>
            );
          }}
        />
      ) : searchLoading ? (
        <View style={styles.centered}>
          <ActivityIndicator size="large" color="#6C3CE1" />
        </View>
      ) : searchError ? (
        <View style={styles.centered}>
          <Text style={styles.errorText}>{searchError}</Text>
        </View>
      ) : (
        <FlatList
          data={searchResults}
          keyExtractor={item => item.scryfall_id}
          contentContainerStyle={styles.searchListContent}
          onRefresh={handleRefresh}
          refreshing={false}
          renderItem={({ item }) => (
            <TouchableOpacity
              style={styles.resultItem}
              onPress={() => {
                setModalView('card');
                setSelectedCard(item);
              }}
            >
              {item.image_uri ? (
                <Image source={{ uri: item.image_uri }} style={styles.resultImage} />
              ) : (
                <View style={[styles.resultImage, styles.placeholderImage]}>
                  <Ionicons name="image-outline" size={24} color="#444" />
                </View>
              )}
              <View style={styles.resultInfo}>
                <View style={styles.nameRow}>
                  <Text style={styles.resultName} numberOfLines={1}>
                    {item.name}
                  </Text>
                  <ManaCost cost={item.mana_cost} />
                </View>
                <Text style={styles.resultType} numberOfLines={1}>
                  {item.type_line}
                </Text>
                <Text style={styles.resultMeta} numberOfLines={1}>
                  {item.set_name} ({item.set_code.toUpperCase()})
                </Text>
              </View>
              <Ionicons name="chevron-forward" size={20} color="#444" />
            </TouchableOpacity>
          )}
          ListEmptyComponent={
            debouncedQuery ? (
              <View style={styles.emptyState}>
                <Text style={styles.emptyTitle}>No cards found</Text>
                <Text style={styles.emptySubtitle}>Nothing matched "{debouncedQuery}".</Text>
              </View>
            ) : (
              <View style={styles.emptyState}>
                <Ionicons name="search-outline" size={48} color="#2a2a3e" />
                <Text style={styles.emptyTitle}>Search for any Magic card</Text>
                <Text style={styles.emptySubtitle}>Results from the full card database appear here.</Text>
              </View>
            )
          }
        />
      )}

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
            {selectedCard && modalView === 'card' ? (
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
                    <View style={styles.modalMana}>
                      <ManaCost cost={selectedCard.mana_cost} size={20} />
                    </View>
                    <Text style={styles.modalType}>{selectedCard.type_line}</Text>
                    <Text style={styles.modalSet}>
                      {selectedCard.set_name} • {selectedCard.rarity}
                    </Text>
                    {isCollectionCard(selectedCard) && selectedCard.pivot.foil ? (
                      <View style={styles.foilBadgeModal}>
                        <Text style={styles.foilBadgeText}>Foil</Text>
                      </View>
                    ) : null}
                  </View>

                  {isCollectionCard(selectedCard) ? (
                    <>
                      <View style={styles.quantitySection}>
                        <Text style={styles.quantityLabel}>Owned</Text>
                        <View style={styles.quantityControls}>
                          <TouchableOpacity
                            style={[styles.qtyButton, updatingCollection && styles.qtyButtonDisabled]}
                            onPress={() => handleQuantityChange(-1)}
                            disabled={updatingCollection}
                          >
                            <Ionicons name="remove" size={20} color="#fff" />
                          </TouchableOpacity>
                          <Text style={styles.quantityValue}>{selectedCard.pivot.quantity}</Text>
                          <TouchableOpacity
                            style={[styles.qtyButton, styles.qtyButtonAdd, updatingCollection && styles.qtyButtonDisabled]}
                            onPress={() => handleQuantityChange(1)}
                            disabled={updatingCollection}
                          >
                            <Ionicons name="add" size={20} color="#fff" />
                          </TouchableOpacity>
                        </View>
                        {updatingCollection ? (
                          <ActivityIndicator size="small" color="#6C3CE1" style={{ marginTop: 4 }} />
                        ) : null}
                      </View>

                      <TouchableOpacity
                        style={styles.deckButton}
                        onPress={handleOpenDeckPicker}
                        disabled={updatingCollection || addingToDeck}
                      >
                        <Ionicons name="albums" size={20} color="#fff" />
                        <Text style={styles.deckButtonText}>Add to Deck</Text>
                      </TouchableOpacity>
                    </>
                  ) : (
                    <View style={styles.actionButtons}>
                      <TouchableOpacity
                        style={styles.actionButton}
                        onPress={() => addToCollection(selectedCard)}
                        disabled={updatingCollection}
                      >
                        {updatingCollection ? (
                          <ActivityIndicator color="#fff" />
                        ) : (
                          <>
                            <Ionicons name="library" size={20} color="#fff" />
                            <Text style={styles.actionButtonText}>Collection</Text>
                          </>
                        )}
                      </TouchableOpacity>

                      <TouchableOpacity
                        style={[styles.actionButton, styles.secondaryActionButton]}
                        onPress={handleOpenDeckPicker}
                        disabled={updatingCollection || addingToDeck}
                      >
                        <Ionicons name="albums" size={20} color="#fff" />
                        <Text style={styles.actionButtonText}>Add to Deck</Text>
                      </TouchableOpacity>
                    </View>
                  )}
                </ScrollView>
              </>
            ) : null}

            {selectedCard && modalView === 'deck-picker' ? (
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
                    keyExtractor={item => item.id.toString()}
                    renderItem={({ item }) => (
                      <TouchableOpacity
                        style={styles.deckSelectItem}
                        onPress={() => addToDeck(item.id)}
                        disabled={addingToDeck}
                      >
                        <View>
                          <Text style={styles.deckSelectName}>{item.name}</Text>
                          <Text style={styles.deckSelectMeta}>
                            {item.format ? item.format.toUpperCase() : 'CASUAL'} • {item.cards_sum_quantity} cards
                          </Text>
                        </View>
                        {addingToDeck ? (
                          <ActivityIndicator size="small" color="#6C3CE1" />
                        ) : (
                          <Ionicons name="add-circle" size={24} color="#6C3CE1" />
                        )}
                      </TouchableOpacity>
                    )}
                    contentContainerStyle={{ paddingHorizontal: 20 }}
                    style={{ maxHeight: 400 }}
                  />
                )}
              </>
            ) : null}
          </View>
        </View>
      </Modal>
    </View>
  );
}

function CardItem({ card }: { card: CollectionCard }) {
  return (
    <View style={styles.cardItem}>
      <View style={styles.cardImageContainer}>
        {card.image_uri ? (
          <Image source={{ uri: card.image_uri }} style={styles.cardImage} resizeMode="cover" />
        ) : (
          <View style={styles.cardImagePlaceholder}>
            <Text style={styles.cardImagePlaceholderText}>{card.name}</Text>
          </View>
        )}
        <View style={styles.cardQtyBadge}>
          <Text style={styles.cardQtyText}>{`\u00D7${card.pivot.quantity}`}</Text>
        </View>
        {card.price_usd != null ? (
          <Text style={styles.cardPriceOverlay}>${card.price_usd.toFixed(2)}</Text>
        ) : null}
        {card.pivot.foil ? (
          <View style={styles.foilBadge}>
            <Text style={styles.foilText}>Foil</Text>
          </View>
        ) : null}
      </View>
      <View style={styles.cardInfo}>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
          <Text style={[styles.cardName, { flex: 1 }]} numberOfLines={1}>{card.name}</Text>
          {card.mana_cost ? <ManaCost cost={card.mana_cost} size={13} /> : null}
        </View>
      </View>
    </View>
  );
}

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
    padding: 40,
  },
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
  modeSwitcher: {
    flexDirection: 'row',
    backgroundColor: '#1a1a2e',
    borderRadius: 10,
    padding: 4,
    marginHorizontal: 16,
    marginBottom: 10,
  },
  modeButton: {
    flex: 1,
    paddingVertical: 8,
    alignItems: 'center',
    borderRadius: 8,
  },
  modeButtonActive: {
    backgroundColor: '#6C3CE1',
  },
  modeButtonText: {
    color: '#888',
    fontSize: 14,
    fontWeight: '600',
  },
  modeButtonTextActive: {
    color: '#fff',
  },
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
  listContent: {
    paddingHorizontal: 12,
    paddingBottom: 24,
  },
  searchListContent: {
    paddingBottom: 20,
  },
  emptyContent: {
    flex: 1,
  },
  row: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    marginBottom: 16,
  },
  cardItem: {
    width: CARD_WIDTH,
    backgroundColor: '#1a1a2e',
    borderRadius: 10,
    overflow: 'hidden',
  },
  cardImageContainer: {
    position: 'relative',
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
  cardQtyBadge: {
    position: 'absolute',
    top: 6,
    left: 6,
    backgroundColor: 'rgba(0,0,0,0.7)',
    paddingHorizontal: 6,
    paddingVertical: 2,
    borderRadius: 4,
  },
  cardQtyText: {
    color: '#fff',
    fontSize: 11,
    fontWeight: '700',
  },
  cardPriceOverlay: {
    position: 'absolute',
    bottom: 6,
    right: 6,
    backgroundColor: 'rgba(0,0,0,0.7)',
    color: '#7dcea0',
    fontSize: 11,
    fontWeight: '700',
    paddingHorizontal: 6,
    paddingVertical: 2,
    borderRadius: 4,
    overflow: 'hidden',
  },
  foilBadge: {
    position: 'absolute',
    top: 6,
    right: 6,
    backgroundColor: 'rgba(61,42,110,0.85)',
    paddingHorizontal: 6,
    paddingVertical: 2,
    borderRadius: 4,
  },
  foilText: {
    color: '#a78bfa',
    fontSize: 11,
    fontWeight: '600',
  },
  cardInfo: {
    padding: 8,
  },
  cardName: {
    color: '#fff',
    fontSize: 13,
    fontWeight: '600',
    marginBottom: 4,
  },
  resultItem: {
    flexDirection: 'row',
    alignItems: 'center',
    padding: 12,
    borderBottomWidth: 1,
    borderBottomColor: '#1a1a2e',
  },
  resultImage: {
    width: 50,
    height: 70,
    borderRadius: 4,
    backgroundColor: '#1a1a2e',
  },
  placeholderImage: {
    alignItems: 'center',
    justifyContent: 'center',
  },
  resultInfo: {
    flex: 1,
    marginLeft: 12,
  },
  nameRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 2,
  },
  resultName: {
    color: '#fff',
    fontSize: 15,
    fontWeight: '600',
    flex: 1,
  },
  manaCostContainer: {
    flexDirection: 'row',
    alignItems: 'center',
    marginLeft: 8,
  },
  resultType: {
    color: '#aaa',
    fontSize: 12,
    marginBottom: 2,
  },
  resultMeta: {
    color: '#666',
    fontSize: 11,
  },
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
    textAlign: 'center',
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
  errorText: {
    color: '#ef4444',
    textAlign: 'center',
  },
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
  modalMana: {
    marginBottom: 8,
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
  actionButtons: {
    flexDirection: 'row',
    gap: 12,
    width: '100%',
    paddingBottom: 20,
  },
  actionButton: {
    flex: 1,
    backgroundColor: '#6C3CE1',
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: 14,
    borderRadius: 12,
  },
  secondaryActionButton: {
    backgroundColor: '#2a2a3e',
  },
  actionButtonText: {
    color: '#fff',
    fontSize: 15,
    fontWeight: '600',
    marginLeft: 8,
  },
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
